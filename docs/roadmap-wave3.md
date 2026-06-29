# Roadmap wave 3 tracker (#1354–#1379)

> **Wave 3 complete on master (May 2026):** language **12/12** + stdlib **13/13** ([#1380](https://github.com/PurHur/php-compiler/issues/1380)). Phase-2 / JIT-only deferrals: [#1936](https://github.com/PurHur/php-compiler/issues/1936) (attributes reflection), [#1776](https://github.com/PurHur/php-compiler/issues/1776) (stdlib JIT audit), [#1238](https://github.com/PurHur/php-compiler/issues/1238).

Living status for the **25 issues** filed May 2026 toward full PHP language + stdlib coverage. Umbrella: [#1380](https://github.com/PurHur/php-compiler/issues/1380). North stars: [#1044](https://github.com/PurHur/php-compiler/issues/1044), [#1492](https://github.com/PurHur/php-compiler/issues/1492) (self-host; was [#1056](https://github.com/PurHur/php-compiler/issues/1056)).

Regenerate capability truth: `php script/capability-matrix.php`, `php script/capability-syntax.php`, `php script/audit-stdlib-jit.php`.

## Summary (May 2026)

| Track | Done | Open |
|-------|-----:|-----:|
| Stdlib (#1367–#1379) | 13 | 0 |
| Language (#1354–#1366) | 12 | 0 |

**Language landed (master):** `never` ([#1358](https://github.com/PurHur/php-compiler/issues/1358) / [#1466](https://github.com/PurHur/php-compiler/pull/1466)), enums ([#1356](https://github.com/PurHur/php-compiler/issues/1356) / [#1463](https://github.com/PurHur/php-compiler/pull/1463)), ctor promotion ([#1359](https://github.com/PurHur/php-compiler/issues/1359) / [#1464](https://github.com/PurHur/php-compiler/pull/1464)), multi-catch ([#1362](https://github.com/PurHur/php-compiler/issues/1362) / [#1468](https://github.com/PurHur/php-compiler/pull/1468)), intersection types ([#1357](https://github.com/PurHur/php-compiler/issues/1357) / [#1474](https://github.com/PurHur/php-compiler/pull/1474)), unpack ([#1361](https://github.com/PurHur/php-compiler/issues/1361) / [#1476](https://github.com/PurHur/php-compiler/pull/1476)), `__serialize` ([#1365](https://github.com/PurHur/php-compiler/issues/1365) / [#1477](https://github.com/PurHur/php-compiler/pull/1477)), first-class callable JIT ([#1363](https://github.com/PurHur/php-compiler/issues/1363) / [#1472](https://github.com/PurHur/php-compiler/pull/1472)), variable-variables JIT ([#1364](https://github.com/PurHur/php-compiler/issues/1364) / [#1381](https://github.com/PurHur/php-compiler/pull/1381)), readonly classes ([#1360](https://github.com/PurHur/php-compiler/issues/1360) / [#1473](https://github.com/PurHur/php-compiler/pull/1473)), WeakReference/WeakMap ([#1366](https://github.com/PurHur/php-compiler/issues/1366) / [#1478](https://github.com/PurHur/php-compiler/pull/1478)).

**Language:** wave 3 language track complete on master (attributes [#1354](https://github.com/PurHur/php-compiler/issues/1354) — VM v1 ignores at runtime; JIT/AOT reflection deferred to phase-2 [#1936](https://github.com/PurHur/php-compiler/issues/1936)).

Related merges outside this wave: `goto` ([#1228](https://github.com/PurHur/php-compiler/issues/1228) / [#1333](https://github.com/PurHur/php-compiler/pull/1333)), anonymous classes ([#1233](https://github.com/PurHur/php-compiler/issues/1233) / [#1386](https://github.com/PurHur/php-compiler/pull/1386)).

**M2 spine:** **3480** / **3480** (`php script/bootstrap-spine-count.php`) — full Phase A inventory in `compiler_lib_spine_smoke`; coverage sync ✅ (`check-selfhost-spine-coverage-sync.php`). Native spine **link** + **lint** ✅ ([#2134](https://github.com/PurHur/php-compiler/issues/2134), [#8559](https://github.com/PurHur/php-compiler/issues/8559)). **M5 daily gate:** `make north-star5-verify-fast` + VM probe ~20ms ([#2201](https://github.com/PurHur/php-compiler/issues/2201)); `--strict` pre-merge only. M4 gen-2→gen-3 recompile ✅. Target doc: [self-host-target.md](self-host-target.md)

## Language (#1354–#1366)

| Issue | Topic | Status | PR / notes |
|-------|--------|--------|------------|
| [#1354](https://github.com/PurHur/php-compiler/issues/1354) | PHP 8 attributes | Closed (VM v1) | Compliance PHPTs `attribute_*.phpt`; ignored at runtime; phase-2 reflection [#1936](https://github.com/PurHur/php-compiler/issues/1936) |
| [#1356](https://github.com/PurHur/php-compiler/issues/1356) | Enum declarations | Closed | [#1463](https://github.com/PurHur/php-compiler/pull/1463) |
| [#1357](https://github.com/PurHur/php-compiler/issues/1357) | Intersection types | Closed (master) | [#1474](https://github.com/PurHur/php-compiler/pull/1474); JIT deferred — `capabilities-syntax.md` |
| [#1358](https://github.com/PurHur/php-compiler/issues/1358) | `never` return type | Closed | [#1466](https://github.com/PurHur/php-compiler/pull/1466) |
| [#1359](https://github.com/PurHur/php-compiler/issues/1359) | Constructor property promotion | Closed | [#1464](https://github.com/PurHur/php-compiler/pull/1464) |
| [#1360](https://github.com/PurHur/php-compiler/issues/1360) | readonly classes | Closed | [#1473](https://github.com/PurHur/php-compiler/pull/1473) |
| [#1361](https://github.com/PurHur/php-compiler/issues/1361) | Array/argument unpack `...$x` | Closed | [#1476](https://github.com/PurHur/php-compiler/pull/1476) |
| [#1362](https://github.com/PurHur/php-compiler/issues/1362) | Multi-type catch | Closed | [#1468](https://github.com/PurHur/php-compiler/pull/1468) |
| [#1363](https://github.com/PurHur/php-compiler/issues/1363) | First-class callable JIT | Closed | [#1472](https://github.com/PurHur/php-compiler/pull/1472) |
| [#1364](https://github.com/PurHur/php-compiler/issues/1364) | Variable variables JIT | Closed | [#1381](https://github.com/PurHur/php-compiler/pull/1381) |
| [#1365](https://github.com/PurHur/php-compiler/issues/1365) | `__serialize` / `__unserialize` | Closed | [#1477](https://github.com/PurHur/php-compiler/pull/1477); VM via `VmSerialize` |
| [#1366](https://github.com/PurHur/php-compiler/issues/1366) | WeakReference / WeakMap | Closed | [#1478](https://github.com/PurHur/php-compiler/pull/1478) |

## Stdlib (#1367–#1379)

| Issue | Function(s) | Status | PR |
|-------|-------------|--------|-----|
| [#1367](https://github.com/PurHur/php-compiler/issues/1367) | `parse_str` | Closed | [#1396](https://github.com/PurHur/php-compiler/pull/1396) |
| [#1368](https://github.com/PurHur/php-compiler/issues/1368) | `setrawcookie` | Closed | [#1403](https://github.com/PurHur/php-compiler/pull/1403) |
| [#1369](https://github.com/PurHur/php-compiler/issues/1369) | `spl_autoload_register` | Closed (VM+JIT) | [#1405](https://github.com/PurHur/php-compiler/pull/1405); JIT runtime link [#2441](https://github.com/PurHur/php-compiler/issues/2441) |
| [#1370](https://github.com/PurHur/php-compiler/issues/1370) | `get_object_vars` | Closed | [#1394](https://github.com/PurHur/php-compiler/pull/1394) |
| [#1371](https://github.com/PurHur/php-compiler/issues/1371) | `trait_exists` / `interface_exists` | Closed | [#1393](https://github.com/PurHur/php-compiler/pull/1393) |
| [#1372](https://github.com/PurHur/php-compiler/issues/1372) | `property_exists` | Closed | [#1387](https://github.com/PurHur/php-compiler/pull/1387) |
| [#1373](https://github.com/PurHur/php-compiler/issues/1373) | `enum_exists` | Closed | [#1383](https://github.com/PurHur/php-compiler/pull/1383) |
| [#1374](https://github.com/PurHur/php-compiler/issues/1374) | `ini_set` | Closed | [#1397](https://github.com/PurHur/php-compiler/pull/1397) |
| [#1375](https://github.com/PurHur/php-compiler/issues/1375) | `pack` | Closed | [#1395](https://github.com/PurHur/php-compiler/pull/1395) |
| [#1376](https://github.com/PurHur/php-compiler/issues/1376) | `sleep` / `usleep` | Closed | [#1389](https://github.com/PurHur/php-compiler/pull/1389) |
| [#1377](https://github.com/PurHur/php-compiler/issues/1377) | `stream_context_create` | Closed (VM) | [#1399](https://github.com/PurHur/php-compiler/pull/1399); JIT arg helper still missing in audit |
| [#1378](https://github.com/PurHur/php-compiler/issues/1378) | `debug_backtrace` | Closed | [#1404](https://github.com/PurHur/php-compiler/pull/1404) |
| [#1379](https://github.com/PurHur/php-compiler/issues/1379) | `set_error_handler` | Closed (VM) | [#1406](https://github.com/PurHur/php-compiler/pull/1406); `restore_error_handler` JIT deferred |

## Do not duplicate

- Wave 2 stdlib/language batch: [#1172](https://github.com/PurHur/php-compiler/issues/1172)–[#1221](https://github.com/PurHur/php-compiler/issues/1221), [#1222](https://github.com/PurHur/php-compiler/issues/1222)–[#1235](https://github.com/PurHur/php-compiler/issues/1235) — largely closed on master.
- Long-standing language: [#72](https://github.com/PurHur/php-compiler/issues/72), [#142](https://github.com/PurHur/php-compiler/issues/142), [#167](https://github.com/PurHur/php-compiler/issues/167), [#169](https://github.com/PurHur/php-compiler/issues/169), [#195](https://github.com/PurHur/php-compiler/issues/195), [#58](https://github.com/PurHur/php-compiler/issues/58), [#145](https://github.com/PurHur/php-compiler/issues/145).

## References

- [capabilities.md](capabilities.md) · [capabilities-syntax.md](capabilities-syntax.md) · [stdlib-jit-audit.md](stdlib-jit-audit.md)
- [local-ci-matrix.md](local-ci-matrix.md) — remote CI disabled; use `./script/ci-local.sh`
- [#78](https://github.com/PurHur/php-compiler/issues/78) roadmap

---

**Wave 3 close-out (May 2026):** language **12/12**, stdlib **13/13** on master. Do not reopen [#1354](https://github.com/PurHur/php-compiler/issues/1354)–[#1379](https://github.com/PurHur/php-compiler/issues/1379) for phase-1 gaps — use phase-2 trackers [#1936](https://github.com/PurHur/php-compiler/issues/1936) (attributes reflection), [#1776](https://github.com/PurHur/php-compiler/issues/1776) (stdlib JIT audit), [#1238](https://github.com/PurHur/php-compiler/issues/1238).
