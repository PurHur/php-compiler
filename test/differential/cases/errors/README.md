# Error / exception differential corpus (#36383)

Compare **stdout, stderr, and exit status** against Zend:

```
script/differential-sweep.sh --stderr --dir test/differential/cases/errors
script/differential-sweep.sh --stderr --aot --dir test/differential/cases/errors
```

Paths are normalised (`script/differential-stderr-normalize.php`); file:line is kept.
Fixtures live under `_fixtures/` and are not cases.
