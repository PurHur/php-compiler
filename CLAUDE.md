# php-compiler

Project instructions live in **[AGENTS.md](AGENTS.md)** — read it before changing anything under
`lib/`, `ext/` or `script/`.

Detailed rules are in `.cursor/rules/*.mdc`; roadmap and release criteria are in `docs/roadmap/`.

The five things most likely to cost you a day, in short:

1. **`CLEAN` on a PR means "no checks configured", not "checks passed"** — there is no CI on `lib/`
   or `ext/`. Name the gate you ran locally.
2. **Neither compliance suite is green on master** (~407 `VMTest`, ~472 `JITTest`). Compare failing
   case **names** against master, never counts, and re-verify any apparent regression individually —
   several cases are order-dependent.
3. **Silent wrong output is the characteristic bug.** Run `script/differential-sweep.sh` (and
   `--aot`) for any change to argument handling, call lowering, operand/slot resolution or CFG shape.
4. **Never make a gate green without making the artifact work** — no restamping; a gate must
   exercise function, not existence. An empty result set is not a pass.
5. **Quote no number you cannot regenerate** from a committed benchmark table with verified-identical
   output.
