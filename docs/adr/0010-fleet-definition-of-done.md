# ADR: Fleet Definition of Done (#36402)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36402 · related #36400

## Decision

A PR may say **`Closes #N`** only when **every Done-when checkbox** on `#N` is
actually satisfied (including gates named in the issue). “Part of #N” is for
incremental slices. Partial closures that leave Done-when red are process bugs.

Verification burden is on the author: name the local gate run; never cite GitHub
`CLEAN` / `MERGEABLE` as evidence (`AGENTS.md`).

## Why

- Early Wave 1 closures were partial (4 of the first 6) — trackers lost signal
  and the next agent re-discovered the same gap (#36400).

## Consequences

- Maintainers reopen or convert to “Part of” when Done-when remains unchecked.
- Status / “Worker lane …” issues are forbidden; use tracker children + ADRs.
