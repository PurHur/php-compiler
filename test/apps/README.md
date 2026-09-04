# Real-world application corpus (#36380)

Pinned open-source PHP packages run under **Zend**, **VM**, and **AOT**. Each app owns a
PHPUnit-free `runner.php` that exercises the package's own fixture suite (or a thin
subset of it). Failures become `test/differential/cases/programs/` reductions — never
app-specific patches.

## Layout

| path | role |
|------|------|
| `MANIFEST.json` | the 12 packages, pinned SHAs, difficulty order |
| `SCOREBOARD.json` | last recorded pass/fail/skip/block counts |
| `<slug>/` | pin file, vendored sources, `runner.php`, `phpc.json`, `run.sh` |
| `docs/pages/apps.html` | generated public scoreboard |

## Commands

```bash
make apps-scoreboard                 # run all ready apps; refresh SCOREBOARD + apps.html
php script/apps/scoreboard.php --check   # ratchet: fail if a package drops below recorded AOT %
php script/apps/generate-page.php        # regenerate docs/pages/apps.html only
```

Wall budget: `< 60 min` in the pinned image for the full set.

## Done-when (issue)

- 12 apps with scoreboard
- Scoreboard gated (pass-rate ratchet)
- First 3 (parsedown, symfony/yaml, brick/math) at **100 % AOT**
