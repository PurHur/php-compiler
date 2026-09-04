# ADR: Memory model — refcount, cycle GC, request arena (#36402)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36402 · related #36388 / #36397

## Decision

1. **Refcount + cycle collector** for heap objects (arrays, objects, strings) —
   same ownership rules for `{main}` as for user functions.
2. **Per-request arena reset** for long-lived workers (`phpc fcgi`) — RSS must be
   flat across soak gates (#36388).
3. Assertions under `PHPC_RUNTIME_ASSERT=1` / ASan builds (#36397) derive from this
   model; they do not invent a second ownership story.

## Why

- Zend’s model is the user-visible contract (destructors, cycles, request teardown).
- Silent leaks and exit-255 on long loops (#15906 / #36148) are memory-discipline
  failures, not “VM is slow” failures.

## Consequences

- Do not add ad-hoc `free` at call sites that bypass refcount.
- Thin C ABI helpers must document ownership in one sentence or stay out of
  `runtime/*.c`.
- FCGI / deploy docs claim memory numbers only from soak gates.
