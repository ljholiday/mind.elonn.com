<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Reasoning\AiUsageRecorder;
use App\Reasoning\ModelRouter;
use App\Reasoning\ModelTier;
use App\Reasoning\ReasoningException;
use App\Security\AuthenticatedService;
use App\Security\ServiceAuthenticator;
use App\Security\SignedRequestVerifier;
use App\Trace\TraceRecorder;
use Throwable;

/**
 * Wires Mind's routes. Mind is an ordinary registered Service with one broad
 * capability: reason over the material it is given and return a canonical
 * Dataset. It does not select or invoke other Services itself -- Conductor
 * decides when Mind participates in satisfying a Call.
 */
final class Application
{
    private Router $router;
    private readonly AiUsageRecorder $aiUsageRecorder;
    private readonly ModelRouter $modelRouter;
    private readonly TraceRecorder $traceRecorder;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        $aiUsage = (array) ($config['ai_usage'] ?? []);
        $this->aiUsageRecorder = new AiUsageRecorder(
            (string) ($aiUsage['path'] ?? ''),
            (int) ($aiUsage['max_records'] ?? 5000)
        );
        $this->modelRouter = new ModelRouter((array) ($config['model'] ?? []), $this->aiUsageRecorder);
        $tracing = (array) ($config['tracing'] ?? []);
        $this->traceRecorder = new TraceRecorder((string) ($tracing['path'] ?? ''));
        $this->router = new Router();
        $this->routes();
    }

    public function handle(): void
    {
        $this->handleRequest(Request::fromGlobals())->send();
    }

    public function handleRequest(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    private function routes(): void
    {
        $this->router->get('/health', fn (): Response => Response::json([
            'status' => 'ok',
            'service' => 'elonn_mind',
        ]));
        $this->router->get('/descriptor', fn (): Response => Response::json(ServiceDescriptor::payload()));

        $this->router->get('/ready', function (): Response {
            $tiers = $this->modelRouter->status();
            $configured = ($tiers['frontier'] ?? '') === 'active';

            return Response::json([
                'status' => $configured ? 'ready' : 'not_ready',
                'service' => 'elonn_mind',
                'dependencies' => [
                    'model' => $configured ? 'configured' : 'not_configured',
                    'model_tiers' => $tiers,
                ],
            ], $configured ? 200 : 500);
        });

        $this->router->get('/metrics', function (Request $request): Response {
            $startedAt = microtime(true);
            $caller = $this->authenticatedService($request);
            if ($caller === null || $caller->name !== 'admin.elonn') {
                return Response::json([
                    'errors' => [[
                        'code' => 'mind.service_auth_failed',
                        'class' => 'auth',
                        'message' => 'Authenticated admin service request is required.',
                    ]],
                ], 401);
            }

            $tiers = $this->modelRouter->status();
            $configured = ($tiers['frontier'] ?? '') === 'active';

            return Response::json([
                'contract_version' => '1.0',
                'service' => 'mind.elonn',
                'status' => $configured ? 'ok' : 'degraded',
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'response_time_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'custom_metrics' => [
                    'model_tiers' => $tiers,
                    'ai_usage_event_count' => $this->aiUsageRecorder->nextSinceId(),
                ],
            ]);
        });

        $this->router->get('/metrics/ai-usage', function (Request $request): Response {
            $caller = $this->authenticatedService($request);
            if ($caller === null || $caller->name !== 'admin.elonn') {
                return Response::json([
                    'errors' => [[
                        'code' => 'mind.service_auth_failed',
                        'class' => 'auth',
                        'message' => 'Authenticated admin service request is required.',
                    ]],
                ], 401);
            }

            $sinceId = max(0, (int) ($_GET['since_id'] ?? 0));
            $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

            return Response::json([
                'events' => $this->aiUsageRecorder->summaries($sinceId, $limit),
                'next_since_id' => $this->aiUsageRecorder->nextSinceId(),
            ]);
        });

        $this->router->get('/metrics/reasoning-traces', function (Request $request): Response {
            $caller = $this->authenticatedService($request);
            if ($caller === null || $caller->name !== 'admin.elonn') {
                return Response::json([
                    'errors' => [[
                        'code' => 'mind.service_auth_failed',
                        'class' => 'auth',
                        'message' => 'Authenticated admin service request is required.',
                    ]],
                ], 401);
            }

            $sinceId = max(0, (int) ($_GET['since_id'] ?? 0));
            $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

            return Response::json([
                'traces' => $this->traceRecorder->summaries($sinceId, $limit),
                'next_since_id' => $this->traceRecorder->nextSinceId(),
            ]);
        });

        $this->router->get('/metrics/reasoning-trace', function (Request $request): Response {
            $caller = $this->authenticatedService($request);
            if ($caller === null || $caller->name !== 'admin.elonn') {
                return Response::json([
                    'errors' => [[
                        'code' => 'mind.service_auth_failed',
                        'class' => 'auth',
                        'message' => 'Authenticated admin service request is required.',
                    ]],
                ], 401);
            }

            $traceId = trim((string) ($_GET['trace_id'] ?? ''));
            $detail = $traceId === '' ? null : $this->traceRecorder->detail($traceId);
            if ($detail === null) {
                return Response::json([
                    'errors' => [[
                        'code' => 'mind.trace_not_found',
                        'class' => 'not_found',
                        'message' => 'Trace was not found.',
                    ]],
                ], 404);
            }

            return Response::json($detail);
        });

        $this->router->get('/administrative-interface', function (Request $request): Response {
            $caller = $this->authenticatedService($request);
            if ($caller === null || $caller->name !== 'admin.elonn') {
                return Response::json([
                    'errors' => [[
                        'code' => 'mind.service_auth_failed',
                        'class' => 'auth',
                        'message' => 'Authenticated admin service request is required.',
                    ]],
                ], 401);
            }

            $checks = [];
            $healthy = true;

            $aiUsagePath = (string) ($this->config['ai_usage']['path'] ?? '');
            if ($aiUsagePath === '' || (!is_file($aiUsagePath) && !is_dir(dirname($aiUsagePath)))) {
                $healthy = false;
                $checks[] = ['name' => 'ai_usage_log', 'state' => 'fail', 'detail' => 'Not configured or path does not exist.'];
            } else {
                $writable = is_file($aiUsagePath) ? is_writable($aiUsagePath) : is_writable(dirname($aiUsagePath));
                $checks[] = ['name' => 'ai_usage_log', 'state' => $writable ? 'ok' : 'warn', 'detail' => 'writable=' . ($writable ? 'true' : 'false')];
            }

            foreach ($this->modelRouter->status() as $tier => $tierStatus) {
                $checks[] = [
                    'name' => 'model_tier_' . $tier,
                    'state' => $tierStatus === 'unconfigured' ? 'warn' : 'ok',
                    'detail' => $tierStatus,
                ];
            }

            $tiersConfig = [];
            foreach ((array) ($this->config['model']['tiers'] ?? []) as $tierKey => $tierConfig) {
                $tiersConfig[$tierKey] = [
                    'enabled' => (bool) ($tierConfig['enabled'] ?? false),
                    'provider' => (string) ($tierConfig['provider'] ?? ''),
                    'name' => (string) ($tierConfig['name'] ?? ''),
                ];
            }

            return Response::json([
                'component' => 'mind.elonn',
                'component_version' => '2',
                'deployment_id' => '',
                'status' => $healthy ? 'running' : 'degraded',
                'health' => [
                    'state' => $healthy ? 'healthy' : 'degraded',
                    'checks' => $checks,
                ],
                'configuration' => [
                    'inspectable' => [
                        'model.timeout_seconds' => (int) ($this->config['model']['timeout_seconds'] ?? 0),
                        'model.output_tokens' => (int) ($this->config['model']['output_tokens'] ?? 0),
                        'model.tiers' => $tiersConfig,
                    ],
                    'mutable' => [],
                ],
                'maintenance' => $this->maintenanceState(),
                'diagnostics' => ['available' => []],
                'control' => [
                    'restart' => ['state' => 'unavailable', 'reason' => 'Mind does not implement an administrative restart operation.'],
                    'drain' => ['state' => 'unavailable', 'reason' => 'Mind does not implement an administrative drain operation.'],
                ],
                'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'metadata' => (object) [],
            ]);
        });

        $this->router->post('/administrative-interface/maintenance', function (Request $request): Response {
            // Mind's first real Admin Plane control capability (ELONN.md, Admin <-> Models:
            // "Models performs administrative operations affecting ... usage limits" --
            // maintenance state is the same kind of administrative operation). Administrative
            // operations are attributable: the acting operator's email is threaded through
            // from admin.elonn.local's own authenticated session, the same pattern Conductor's
            // registry approve/reject already uses for operator_member_id.
            $caller = $this->authenticatedService($request);
            if ($caller === null || $caller->name !== 'admin.elonn') {
                return Response::json([
                    'errors' => [[
                        'code' => 'mind.service_auth_failed',
                        'class' => 'auth',
                        'message' => 'Authenticated admin service request is required.',
                    ]],
                ], 401);
            }

            $body = is_array($request->parsedBody()) ? $request->parsedBody() : [];
            $state = trim((string) ($body['state'] ?? ''));
            if (!in_array($state, ['normal', 'maintenance'], true)) {
                return Response::json([
                    'errors' => [[
                        'code' => 'mind.invalid_maintenance_state',
                        'class' => 'contract',
                        'message' => 'state must be "normal" or "maintenance".',
                    ]],
                ], 400);
            }

            $reason = trim((string) ($body['reason'] ?? ''));
            $operatorEmail = trim((string) ($body['operator_email'] ?? ''));

            $written = $this->writeMaintenanceState([
                'state' => $state,
                'reason' => $state === 'maintenance' ? $reason : '',
                'changed_by' => $operatorEmail,
                'changed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            if (!$written) {
                return Response::json([
                    'errors' => [[
                        'code' => 'mind.maintenance_write_failed',
                        'class' => 'internal',
                        'message' => 'The maintenance state could not be persisted.',
                    ]],
                ], 500);
            }

            return Response::json(['maintenance' => $this->maintenanceState()]);
        });

        $this->router->get('/', fn (): Response => Response::json([
            'service' => 'elonn_mind',
            'description' => 'Elonn Mind reasoning Service.',
            'owns' => [
                'General-purpose reasoning over material supplied in a Call',
            ],
            'does_not_own' => [
                'Service orchestration',
                'Service discovery',
                'Session management',
                'Runtime presentation',
            ],
            'routes' => [
                'GET /health',
                'GET /ready',
                'POST /mind/call',
            ],
        ]));

        $this->router->post('/mind/call', function (Request $request): Response {
            $caller = $this->authenticatedService($request);
            if ($caller === null) {
                return $this->datasetError('mind.service_auth_failed', 'auth', 'Mind service authentication failed.', 401);
            }

            $call = $request->parsedBody();
            $startedAt = microtime(true);

            $maintenance = $this->maintenanceState();
            if ($maintenance['state'] === 'maintenance') {
                return $this->respondWithTrace($call, $startedAt, $this->datasetErrorPayload(
                    'mind.maintenance',
                    'dependency',
                    $maintenance['reason'] !== '' ? $maintenance['reason'] : 'Mind is in administrative maintenance.',
                    $caller
                ), 503);
            }

            $content = is_array($call['content'] ?? null) ? $call['content'] : [];
            $operation = (string) ($content['operation'] ?? '');
            if ($operation === '') {
                return $this->respondWithTrace($call, $startedAt, $this->datasetErrorPayload('mind.operation_required', 'invalid_call', 'Mind Call content.operation is required.', $caller), 400);
            }

            if ($operation !== 'mind.reason') {
                return $this->respondWithTrace($call, $startedAt, $this->datasetErrorPayload('mind.unsupported_operation', 'invalid_call', 'Mind operation is not supported.', $caller), 422);
            }

            $material = trim((string) ($content['material'] ?? ''));
            if ($material === '') {
                return $this->respondWithTrace($call, $startedAt, $this->datasetErrorPayload('mind.material_required', 'invalid_call', 'Mind reasoning requires material.', $caller), 422);
            }

            $tier = ModelTier::fromRequest($content['tier'] ?? null);

            try {
                $result = $this->modelRouter->clientFor($tier, $caller->name)->reason($material);
            } catch (ReasoningException $exception) {
                return $this->respondWithTrace($call, $startedAt, $this->datasetErrorPayload($exception->errorCode, $exception->errorClass, $exception->getMessage(), $caller), $exception->httpStatus);
            } catch (Throwable $throwable) {
                error_log('[mind] mind.reason failed: ' . $throwable->getMessage());
                return $this->respondWithTrace($call, $startedAt, $this->datasetErrorPayload('mind.reasoning_failed', 'dependency', 'Mind was unable to complete reasoning.', $caller), 503);
            }

            return $this->respondWithTrace($call, $startedAt, $this->reasoningDataset($material, $result, $caller), 201);
        });
    }

    /**
     * Records this request's reasoning trace -- the Call Conductor sent, the Dataset Mind is
     * about to return (success or error shape alike, mirroring conductor.elonn.local's own
     * TraceRecorder::record() call in Application::call()), and this request's AI usage totals --
     * then returns the Dataset as the actual HTTP response. Every post-authentication outcome of
     * POST /mind/call goes through this, so admin.elonn's Mind trace viewer sees every real
     * attempt, not just successful ones.
     *
     * @param array<string, mixed> $call
     * @param array<string, mixed> $dataset
     */
    private function respondWithTrace(array $call, float $startedAt, array $dataset, int $status): Response
    {
        $this->traceRecorder->record($call, $dataset, $startedAt, $this->aiUsageRecorder->requestTotals());

        return Response::json($dataset, $status);
    }

    /**
     * @return array{state:string, reason:string, changed_by?:string, changed_at?:string}
     */
    private function maintenanceState(): array
    {
        $path = (string) ($this->config['maintenance']['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return ['state' => 'normal', 'reason' => ''];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !in_array($decoded['state'] ?? null, ['normal', 'maintenance'], true)) {
            return ['state' => 'normal', 'reason' => ''];
        }

        return [
            'state' => (string) $decoded['state'],
            'reason' => (string) ($decoded['reason'] ?? ''),
            'changed_by' => (string) ($decoded['changed_by'] ?? ''),
            'changed_at' => (string) ($decoded['changed_at'] ?? ''),
        ];
    }

    /**
     * @param array{state:string, reason:string, changed_by:string, changed_at:string} $state
     */
    private function writeMaintenanceState(array $state): bool
    {
        $path = (string) ($this->config['maintenance']['path'] ?? '');
        if ($path === '') {
            return false;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
            return false;
        }

        $written = @file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $written !== false;
    }

    private function authenticatedService(Request $request): ?AuthenticatedService
    {
        /** @var array<string, string> $tokens */
        $tokens = $this->config['service_auth'] ?? [];
        $verifier = new SignedRequestVerifier((string) ($tokens['conductor_keys_url'] ?? ''));

        return (new ServiceAuthenticator($tokens, $verifier))->authenticate($request);
    }

    /** @return array<string, mixed> */
    private function reasoningDataset(string $material, \App\Reasoning\ReasoningResult $result, AuthenticatedService $caller): array
    {
        $objectId = 'mind.reasoning:' . bin2hex(random_bytes(16));
        $object = [
            'id' => $objectId,
            'type' => 'mind.reasoning',
            'title' => 'Reasoning result',
            'summary' => $result->response,
            'content' => [
                'material' => $material,
                'response' => $result->response,
                'model' => $result->model,
            ],
            'resources' => [],
            'permissions' => ['can_view' => true, 'can_act' => false, 'can_share' => false],
            'relationships' => [],
            'metadata' => ['service' => 'mind'],
        ];

        return [
            'id' => 'dataset:service:mind:' . bin2hex(random_bytes(16)),
            'type' => 'service',
            'scope' => 'object',
            'mode' => 'snapshot',
            'created' => gmdate('c'),
            'objects' => [$object],
            'actions' => [],
            'relationships' => [],
            'collections' => [],
            'resources' => [],
            'placements' => [['id' => 'placement:' . $objectId . ':carry', 'type' => 'carry', 'content' => ['object' => $objectId]]],
            'errors' => [],
            'context' => ['service' => 'mind', 'operation' => 'mind.reason', 'caller' => $caller->name],
        ];
    }

    /**
     * Pre-authentication failure only: there is no authenticated caller and no real Call worth
     * tracing yet, so this returns directly rather than going through respondWithTrace().
     */
    private function datasetError(string $code, string $class, string $message, int $status, ?AuthenticatedService $caller = null): Response
    {
        return Response::json($this->datasetErrorPayload($code, $class, $message, $caller), $status);
    }

    /** @return array<string, mixed> */
    private function datasetErrorPayload(string $code, string $class, string $message, ?AuthenticatedService $caller = null): array
    {
        $context = [
            'service' => 'mind',
        ];
        if ($caller !== null) {
            $context['caller'] = $caller->name;
            $context['owner'] = $caller->owner();
        }

        return [
            'id' => 'dataset:service:mind:' . bin2hex(random_bytes(16)),
            'type' => 'service',
            'scope' => 'object',
            'mode' => 'snapshot',
            'created' => gmdate('c'),
            'objects' => [],
            'actions' => [],
            'relationships' => [],
            'collections' => [],
            'resources' => [],
            'placements' => [],
            'errors' => [[
                'code' => $code,
                'class' => $class,
                'message' => $message,
            ]],
            'context' => $context,
        ];
    }
}
