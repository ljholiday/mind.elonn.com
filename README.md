# Mind
mind.elonn.local/README.md

## Overview

Mind is Elonn's first-party reasoning Service.

Mind provides general-purpose reasoning for the Elonn platform. It transforms canonical Calls into canonical Datasets using its configured Models and any additional Services required to satisfy the Call.

Mind is a Service. It participates in the platform alongside Maps, Messages, Calendar, Paint, Time, and every other domain capability.

## Responsibilities

Mind is responsible for:

* Receiving canonical Calls.
* Understanding the member's request.
* Reasoning over the information available in the Call.
* Determining when additional capabilities are required.
* Selecting and invoking Services as needed.
* Continuing reasoning as new information becomes available.
* Assembling a canonical Dataset.
* Returning the Dataset.

## Architecture

```text
Conductor
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
Conductor
```

Mind owns reasoning.

Services own domain capabilities.

Providers implement external integrations for individual Services.

## Models

Mind performs reasoning using one or more configured Models.

Models are internal components of Mind. They analyze Calls, evaluate available information, determine when additional capabilities are required, and guide the reasoning process.

## Services

Mind uses Services when reasoning requires capabilities beyond those provided by its Models.

Examples include:

* Maps
* Messages
* Calendar
* Time
* Search
* Storage

Mind communicates with every Service through canonical Service Calls and receives canonical Service Datasets in return.

## Calls

Mind receives canonical Calls.

Calls are immutable throughout the reasoning session.

## Dataset

Every reasoning session produces one canonical Dataset.

The Dataset represents the complete result of the reasoning process.

Before returning a Dataset, Mind validates it against the canonical Dataset specification.

## Authentication

Call authentication is performed before reasoning begins.

Mind authenticates when communicating with other Services.

Member identity is preserved throughout the reasoning session.

## Validation

Mind validates:

* incoming Calls
* outgoing Service Calls
* returned Service Datasets
* the final Dataset

Validation failures become canonical errors.

## Metrics

Mind records operational metrics including Model usage, reasoning duration, Service usage, latency, failures, and contract violations.

## Tracing

Every reasoning session is traceable.

Tracing records reasoning activity, Model interactions, Service Calls, returned Datasets, timing information, and errors.

## Contracts

Mind communicates exclusively through canonical contracts.

* Canonical Calls
* Canonical Service Calls
* Canonical Service Datasets
* Canonical Datasets

Canonical data structures and contracts are maintained in the shared Elonn canonical and contract specifications.

