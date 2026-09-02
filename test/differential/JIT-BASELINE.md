# JIT differential baseline (#36221)

`script/differential-sweep.sh --jit` compares `bin/jit.php` (MCJIT) against Zend
(stdout+stderr).

## Program corpus (`test/differential/cases/programs`)

```bash
script/differential-sweep.sh --dir test/differential/cases/programs   # VM must be 30/30
script/differential-sweep.sh --jit --dir test/differential/cases/programs
```

### Measured (`d942191d3f` parent tip / corpus `85d9ec7fdc`, 2026-09-02)

```text
0/30 match Zend (jit backend), exit 30
```

Every program emits a leading diagnostic then (usually) the correct Zend payload:

`phpc: JIT deferred to VM: typed non-void return (#2114)`

Some OOP programs also emit `phpc: JIT deferred to VM: user class declared instance property (#5111)`.

| case | notes |
|---|---|
| `p01`–`p13`, `p15`–`p30` | payload matches Zend after the deferral diagnostic(s) |
| `p14_references_swap.php` | deferral diagnostic **plus** `Hash table is already destroyed` |

Root cause for the universal DIFF is #2114 / #36222 (whole-script VM fallback for typed non-void returns). Prefer fixing the tier, or `@differential-skip-jit:` with a reason — never drop the diagnostic check silently.

Update this table after each JIT sweep — failing **names**, not counts.
