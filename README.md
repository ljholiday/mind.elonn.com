# Mind
mind.elonn.local/README.md
For github and other interested parties.

Mind is Elonn's first-party reasoning Service.

It provides high-quality reasoning through the same Service architecture used by every other Service on the platform. Members benefit from a reasoning capability that is integrated with the rest of the Elonn ecosystem while remaining independent of any particular model provider.

Mind does not orchestrate requests. It performs reasoning in response to Calls received through its published Service contract and returns the results as a canonical Dataset.

## Responsibilities

Mind:

* Accepts canonical Calls.
* Performs reasoning using one or more underlying models.
* Produces canonical Datasets.
* May use external model providers or locally hosted models.
* Presents a stable Service contract regardless of implementation.

Mind does not:

* Coordinate other Services.
* Select which Services should participate in a request.
* Manage sessions.
* Present user interfaces.
* Maintain Runtime state.

## Architecture

Mind participates in the platform exactly like every other Service.

```text
Runtime
    │
    ▼
World
    │
    ▼
Conductor
    │
    ├──► Mind
    ├──► Maps
    ├──► Time
    ├──► Social
    └──► Third-party Services
             │
             ▼
         Dataset
             │
             ▼
        Conductor
             │
             ▼
           World
             │
             ▼
          Runtime
```

Conductor determines when Mind should participate in satisfying a Call.

## Models

Mind is model-independent.

The implementation may use local models, hosted models, commercial APIs, or future reasoning providers without changing the published Service contract.

Replacing or adding models does not affect consumers of the Mind Service.

## Contracts

Mind publishes one Service contract.

Request:

* Call

Response:

* Dataset

Consumers interact with Mind exclusively through this contract.

## Canonical Data

Mind consumes and produces only canonical Elonn data structures.

Implementation-specific formats are internal implementation details.

## Related Documentation

* `MIND.md`
* Service Contract Authoring Guide
* `ELONN.md`

