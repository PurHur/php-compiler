# Program-shaped differential corpus (#36221)

Thirty deterministic end-to-end programs. Each prints a stable summary plus a `checksum=` line (crc32).

```bash
script/differential-sweep.sh --dir test/differential/cases/programs
script/differential-sweep.sh --aot --dir test/differential/cases/programs
script/differential-sweep.sh --jit --dir test/differential/cases/programs
```

Baselines: `test/differential/AOT-BASELINE.md`, `test/differential/JIT-BASELINE.md`.
