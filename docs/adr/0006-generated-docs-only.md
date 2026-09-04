# ADR: Generated docs only (#36402)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36402 · related #15621 / #36395

## Decision

Capability matrices, bootstrap inventory, extension registry, configuration docs,
status snapshots, and similar tables are **generated**. Hand-edited cells that
diverge from generators are bugs. The pre-merge gate is:

```bash
./script/check-generated-docs.sh
```

## Why

- Hand-maintained AOT columns and README numbers drifted for months while gates
  stayed green (architecture review F6; #36395).
- Agents regenerate in the pinned Docker image so host PHP skew cannot invent builtins.

## Consequences

- PRs that change advertisements / builtins / inventory must regenerate inside
  `./script/docker-exec.sh`.
- Quote no benchmark number you cannot regenerate from a committed table with
  verified-identical output.
