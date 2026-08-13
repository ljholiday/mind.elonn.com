# MIND
mind.elonn.local/MIND.md

## Overview

Mind is Elonn's reasoning component.

World sends Mind a canonical Call. Mind reasons over that Call, invokes Services when additional capabilities are required, and returns a canonical Dataset to World.

Mind owns reasoning.

World owns member and world context.

Services own domain capabilities.

Providers implement external integrations for individual Services.

---

## Responsibilities

Mind is responsible for:

- Receiving canonical Calls from World.
- Validating incoming Calls.
- Understanding the member's request.
- Determining what information and capabilities are required.
- Discovering available Services and their capabilities.
- Selecting Models for each stage of reasoning.
- Constructing canonical Service Calls.
- Invoking Services.
- Validating returned Service Datasets.
- Continuing reasoning using newly acquired information.
- Determining when reasoning is complete.
- Assembling the final canonical Dataset.
- Returning the Dataset to World.

---

## Architecture

```
Runtime
    │
    ▼
World
    │
Canonical Call
    ▼
Mind
    │
Canonical Service Calls
    ▼
Services
    │
Canonical Service Datasets
    ▼
Mind
    │
Canonical Dataset
    ▼
World
```

Mind is the only component that performs reasoning.

---

## Calls

Mind receives canonical Calls from World.

A Call contains the member's request together with the context World has chosen to provide.

Mind treats the Call as immutable.

---

## Reasoning

Reasoning is the process of transforming a Call into a Dataset.

Mind reasons over:

- the incoming Call
- the context supplied by World
- the capabilities published by available Services
- information returned by Services

As reasoning progresses, Mind determines what additional capabilities are required and invokes the appropriate Services.

Reasoning concludes when Mind determines that no additional Service operations are required to continue processing the Call.

---

## Models

Models perform reasoning on behalf of Mind.

Mind is configured with one or more available Models.

During a reasoning session, Mind selects the appropriate Model or Models for each reasoning task.

The process used to select Models is internal to Mind and may evolve without affecting World or Services.

---

## Services

Services publish the capabilities they provide.

Mind discovers those capabilities and uses them during reasoning.

Mind communicates with Services exclusively through canonical Service Calls.

Services communicate with Mind exclusively through canonical Service Datasets.

Mind never communicates directly with external Providers.

---

## Dataset

Every Call produces one canonical Dataset.

Mind assembles the Dataset from the complete reasoning session.

Before returning the Dataset to World, Mind validates it against the canonical Dataset specification.

---

## Authentication

World authenticates to Mind.

Mind authenticates to Services.

Member identity supplied by World is preserved throughout the reasoning session.

---

## Validation

Mind validates:

- incoming Calls
- outgoing Service Calls
- returned Service Datasets
- the final Dataset

Validation failures become canonical errors.

---

## Metrics

Mind records operational metrics for every reasoning session, including reasoning duration, Model usage, Service usage, latency, failures, and contract violations.

---

## Tracing

Every reasoning session is traceable.

Tracing records reasoning decisions, Model interactions, Service Calls, returned Datasets, timing, and errors.

---

## Invariants

The following statements are always true.

- World communicates with Mind using canonical Calls.
- Mind communicates with Services using canonical Service Calls.
- Services communicate with Mind using canonical Service Datasets.
- Mind communicates with World using canonical Datasets.
- Mind owns reasoning.
- World owns context.
- Services own capabilities.
- Providers are accessed only through Services.
