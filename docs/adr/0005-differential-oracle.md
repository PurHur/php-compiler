# ADR: Differential-against-Zend is the primary correctness oracle (#36402)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36402 · `AGENTS.md` · `.cursor/rules/correctness-gates.mdc`

## Decision

**Observable behavior is compared to Zend (php-src), not to recorded compliance
fixtures alone.**

Mandatory for argument handling, call lowering, operand/slot resolution, or CFG
shape changes:

```bash
script/differential-sweep.sh
script/differential-sweep.sh --aot --repeat 3   # before merge when memory-safety risk
```

Compliance suites (~407 VM / ~472 JIT red on master) are **set-difference**
signals only — never raw fail counts. `CLEAN` on GitHub means “no checks
configured”, not green.

## Why

- Compliance only catches what someone already recorded; silent wrong output is
  the characteristic bug (#23354 found 24 defects the suite missed).
- Intermittent heap corruption needs `--repeat`, not a single lucky run (#23842).

## Consequences

- `@differential-skip-aot` only for genuinely unsupported features with a reason.
- Empty PHPUnit filters / empty globs are failures (`artifact-honesty.mdc`).
