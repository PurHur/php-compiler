# JIT differential baseline (#36221)

`script/differential-sweep.sh --jit` compares `bin/jit.php` (MCJIT) against Zend.

## Program corpus (`test/differential/cases/programs`)

Captured during #36221. Re-run:

```bash
script/differential-sweep.sh --dir test/differential/cases/programs          # VM must be 30/30
script/differential-sweep.sh --jit --dir test/differential/cases/programs
```

Cases that exercise shapes MCJIT still whole-script-falls-back on may use
`@differential-skip-jit:` with a reason (#98 / #36222). Prefer recording a real DIFF
over skipping when the backend produces output.

Update this file after each JIT sweep of the program corpus — list failing **names**,
not counts.
