# Differential fuzz harness (#36398)

Grammar-based PHP program generator + Zend-oracle runner + line reducer.

## Generate

```bash
./script/docker-exec.sh -- bash -lc 'php script/fuzz/gen.php --seed 42'
./script/docker-exec.sh -- bash -lc 'php script/fuzz/gen.php --seed 42 --shape string_concat_loop --out /tmp/p.php'
```

## Run (Zend vs VM / AOT)

```bash
./script/docker-exec.sh -- bash -lc \
  'php script/fuzz/run.php --count 50 --seed-base 1 --backend vm --keep-failures build/fuzz-fail'
./script/docker-exec.sh -- bash -lc \
  'php script/fuzz/run.php --count 20 --seed-base 100 --backend both --keep-failures build/fuzz-fail'
```

Failures are deduped by normalised stdout/stderr/exit signature. Unique failures land in `--keep-failures` as `.php` + `.json`.

## Reduce

```bash
./script/docker-exec.sh -- bash -lc \
  'php script/fuzz/reduce.php --in build/fuzz-fail/vm_diff_seed12.php --backend vm --out build/fuzz-fail/reduced.php'
```

Aim for ≤ 15-line reproducers; attach fixed cases under `test/differential/cases/fuzz/`.

## Seed corpus (gate)

The fuzz seed corpus is part of the **default** differential sweep (#36398 Done-when):

```bash
./script/docker-exec.sh -- bash -lc 'script/differential-sweep.sh'
# → also runs test/differential/cases/fuzz/*.php and asserts fuzz/COUNT

# Or target the corpus alone:
./script/docker-exec.sh -- bash -lc \
  'script/differential-sweep.sh --dir test/differential/cases/fuzz'
./script/docker-exec.sh -- bash -lc \
  'script/differential-sweep.sh --aot --dir test/differential/cases/fuzz --repeat 3'
```

## Nightly batch (2k / 60 min gate)

```bash
make fuzz-nightly
# or:
./script/fuzz/nightly.sh
# smaller local check:
FUZZ_NIGHTLY_COUNT=100 FUZZ_NIGHTLY_WALL_SEC=600 ./script/fuzz/nightly.sh
```

Writes `build/fuzz-nightly/report.json` (elapsed, unique signatures, ≤15-line reduce rate).
Fails if the batch does not finish within `FUZZ_NIGHTLY_WALL_SEC` (default 3600), or if
≥5 unique failures reduce and fewer than 80% are ≤15 nonempty lines.

## Signature issue filing (slice 3)

```bash
# After a keep-failures / reduce pass:
./script/docker-exec.sh -- bash -lc \
  'php script/fuzz/file-signatures.php --failures-dir build/fuzz-nightly/failures \
   --reduced-dir build/fuzz-nightly/reduced --dry-run'

# Write drafts + update test/differential/cases/fuzz/SIGNATURES.json:
./script/docker-exec.sh -- bash -lc \
  'php script/fuzz/file-signatures.php --failures-dir build/fuzz-nightly/failures \
   --reduced-dir build/fuzz-nightly/reduced'

# Also create GitHub issues (deduped by registry):
./script/docker-exec.sh -- bash -lc \
  'php script/fuzz/file-signatures.php --failures-dir build/fuzz-nightly/failures \
   --reduced-dir build/fuzz-nightly/reduced --create'

# From nightly (drafts only unless CREATE=1):
FUZZ_NIGHTLY_FILE_ISSUES=1 make fuzz-nightly
FUZZ_NIGHTLY_FILE_ISSUES=1 FUZZ_NIGHTLY_CREATE_ISSUES=1 make fuzz-nightly
```

ASan / soft AOT nightly remain optional follow-ups of #36398.
Coverage-biased `auto` shape pick is on by default (`FUZZ_BIAS=coverage`);
set `FUZZ_BIAS=uniform` for the old equal-weight behaviour.
