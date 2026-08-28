<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Reasoning\AiUsageRecorder;
use App\Reasoning\OpenAiReasoningClient;
use App\Reasoning\ReasoningException;
use App\Reasoning\ReasoningModelClient;
use App\Reasoning\UnavailableReasoningClient;
use App\Security\AuthenticatedService;
use App\Security\ServiceAuthenticator;
use App\Security\SignedRequestVerifier;
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

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        $aiUsage = (array) ($config['ai_usage'] ?? []);
        $this->aiUsageRecorder = new AiUsageRecorder(
            (string) ($aiUsage['path'] ?? ''),
            (array) ($config['model']['pricing'] ?? []),
            (int) ($aiUsage['max_records'] ?? 5000)
        );
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
            $model = (array) ($this->config['model'] ?? []);
            $provider = (string) ($model['provider'] ?? '');
            $configured = $provider === 'openai'
                && (string) ($model['openai']['api_key'] ?? '') !== ''
                && (string) ($model['openai']['responses_url'] ?? '') !== '';

            $dependencies = [
                'model_provider' => $provider !== '' ? $provider : 'unconfigured',
                'model' => $configured ? 'configured' : 'not_configured',
            ];

            return Response::json([
                'status' => $configured ? 'ready' : 'not_ready',
                'service' => 'elonn_mind',
                'dependencies' => $dependencies,
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

            $model = (array) ($this->config['model'] ?? []);
            $configured = (string) ($model['provider'] ?? '') === 'openai'
                && (string) ($model['openai']['api_key'] ?? '') !== '';

            return Response::json([
                'contract_version' => '1.0',
                'service' => 'mind.elonn',
                'status' => $configured ? 'ok' : 'degraded',
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'response_time_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'custom_metrics' => [
                    'model_provider' => (string) ($model['provider'] ?? ''),
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

            $content = is_array($request->parsedBody()['content'] ?? null) ? $request->parsedBody()['content'] : [];
            $operation = (string) ($content['operation'] ?? '');
            if ($operation === '') {
                return $this->datasetError('mind.operation_required', 'invalid_call', 'Mind Call content.operation is required.', 400, $caller);
            }

            if ($operation !== 'mind.reason') {
                return $this->datasetError('mind.unsupported_operation', 'invalid_call', 'Mind operation is not supported.', 422, $caller);
            }

            $material = trim((string) ($content['material'] ?? ''));
            if ($material === '') {
                return $this->datasetError('mind.material_required', 'invalid_call', 'Mind reasoning requires material.', 422, $caller);
            }

            try {
                $result = $this->reasoningClient($caller->name)->reason($material);
            } catch (ReasoningException $exception) {
                return $this->datasetError($exception->errorCode, $exception->errorClass, $exception->getMessage(), $exception->httpStatus, $caller);
            } catch (Throwable $throwable) {
                error_log('[mind] mind.reason failed: ' . $throwable->getMessage());
                return $this->datasetError('mind.reasoning_failed', 'dependency', 'Mind was unable to complete reasoning.', 503, $caller);
            }

            return Response::json($this->reasoningDataset($material, $result, $caller), 201);
        });
    }

    private function authenticatedService(Request $request): ?AuthenticatedService
    {
        /** @var array<string, string> $tokens */
        $tokens = $this->config['service_auth'] ?? [];
        $verifier = new SignedRequestVerifier((string) ($tokens['conductor_keys_url'] ?? ''));

        return (new ServiceAuthenticator($tokens, $verifier))->authenticate($request);
    }

    private function reasoningClient(string $requesterService): ReasoningModelClient
    {
        $model = (array) ($this->config['model'] ?? []);
        if ((string) ($model['provider'] ?? '') !== 'openai') {
            return new UnavailableReasoningClient();
        }

        $openai = (array) ($model['openai'] ?? []);

        return new OpenAiReasoningClient(
            (string) ($openai['responses_url'] ?? ''),
            (string) ($openai['api_key'] ?? ''),
            (string) ($model['name'] ?? 'gpt-5.6'),
            (int) ($model['timeout_seconds'] ?? 20),
            (int) ($model['output_tokens'] ?? 600),
            $this->aiUsageRecorder,
            $requesterService
        );
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

    private function datasetError(string $code, string $class, string $message, int $status, ?AuthenticatedService $caller = null): Response
    {
        $context = [
            'service' => 'mind',
        ];
        if ($caller !== null) {
            $context['caller'] = $caller->name;
            $context['owner'] = $caller->owner();
        }

        return Response::json([
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
        ], $status);
    }
}
