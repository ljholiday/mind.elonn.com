# Mind Service Contract

`mind.elonn.local` is Elonn's general-purpose reasoning Service.

Mind reasons over material it is given and returns a canonical Dataset. It is one Service among many
-- it does not coordinate other Services or decide when it participates in satisfying a request.
Conductor determines when Mind should be invoked and how Mind's Dataset contributes to the overall
result.

## Contract

- Service id: `mind.elonn`
- Domain: `reasoning`
- Revision: `1`
- Published: `2026-08-23T00:00:00Z`
- Canonical JSON: `https://mind.elonn.com/mind.json`
- Service Publication: `https://mind.elonn.com/mind-publication.json`

The canonical JSON contract is authoritative. This Markdown document describes the same Service contract
for human readers.

## Authentication

Mind requires authenticated platform Service calls using Conductor signed requests.

`POST /mind/call` accepts authenticated Calls from `conductor.elonn`.

## Endpoint

### `POST /mind/call`

Accepts one canonical `Call` and returns one canonical Service `Dataset`.

The Call must include:

- `id`
- `content`
- `context`

The `content.operation` value selects the Mind operation.

## Operations

### `mind.reason`

Reason over the material supplied in the Call and return the reasoning result as a canonical Dataset.
Model-selectable -- a general question, synthesis, or interpretation request can resolve here. Takes one
argument, `material` (required) -- the text Mind should reason over.

Mind may interpret natural language, apply logical reasoning, generate structured information, and
synthesize information supplied in the Call. The implementation may use one or more reasoning models;
which models, and how they are selected, is an implementation detail and is not part of this Contract.
Replacing or upgrading models does not change how consumers invoke Mind.

## Response

`mind.reason` returns one canonical Service `Dataset` containing one `mind.reasoning` object, whose
`content.response` carries Mind's reasoning result as text.

## Side Effects

`mind.reason` is read-only. Mind does not persist material or reasoning results between Calls.

## Privacy

Mind reasons only over the material supplied in the Call it receives for that one request.

## Errors

Mind may return these errors in the response Dataset:

- `mind.service_auth_failed`
- `mind.operation_required`
- `mind.unsupported_operation`
- `mind.material_required`
- `mind.model_unavailable`
- `mind.model_malformed`
- `mind.reasoning_failed`
