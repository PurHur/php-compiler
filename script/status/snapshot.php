#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Honest status snapshot for README / docs (#36395).
 *
 * Lives under script/status/ so it does not bump the top-level script/ file-count
 * ratchet (#36403). Numbers come only from committed artifacts.
 *
 * Usage:
 *   php script/status/snapshot.php              # write JSON + render README/docs
 *   php script/status/snapshot.php --check      # fail on drift / stale claims
 *   php script/status/snapshot.php --json-only  # write JSON only
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';
require_once $root . '/script/bootstrap-lib.php';
require_once $root . '/script/bootstrap-phase-a-deferred.php';
require_once $root . '/script/bootstrap-spine-count.php';

$checkOnly = in_array('--check', $argv, true);
$jsonOnly = in_array('--json-only', $argv, true);

$snapshot = status_snapshot_collect($root);
$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$docsPath = $root . '/docs/status-snapshot.json';
$buildPath = $root . '/build/status.json';

if ($checkOnly) {
    exit(status_snapshot_check($root, $snapshot, $docsPath));
}

if (!is_dir($root . '/build') && !mkdir($root . '/build') && !is_dir($root . '/build')) {
    fwrite(STDERR, "status-snapshot: cannot create build/\n");
    exit(1);
}

if (false === file_put_contents($docsPath, $json)) {
    fwrite(STDERR, "status-snapshot: failed writing {$docsPath}\n");
    exit(1);
}
if (false === file_put_contents($buildPath, $json)) {
    fwrite(STDERR, "status-snapshot: failed writing {$buildPath}\n");
    exit(1);
}

fwrite(STDOUT, "status-snapshot: wrote docs/status-snapshot.json and build/status.json\n");

if (!$jsonOnly) {
    render_status_docs($root, $snapshot);
}

exit(0);

/**
 * @return array<string, mixed>
 */
function status_snapshot_collect(string $root): array
{
    $counts = bootstrap_spine_counts($root);
    $caps = status_snapshot_capability_counts($root . '/docs/capabilities.md');
    $apps = status_snapshot_apps($root . '/test/apps/SCOREBOARD.json');
    $diffCases = status_snapshot_count_php_files($root . '/test/differential/cases');
    require_once $root . '/script/status/ci-streak-lib.php';
    $streak = ci_streak_load($root . '/docs/ci-streak.json');

    return [
        'generated_by' => 'script/status/snapshot.php',
        'issue' => 36395,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'spine' => (int) $counts['spine'],
        'inventory' => (int) $counts['inventory'],
        'builtins_matrix_rows' => $caps['rows'],
        'builtins_vm_yes' => $caps['vm_yes'],
        'builtins_jit_yes' => $caps['jit_yes'],
        'builtins_aot_yes' => $caps['aot_yes'],
        'differential_cases' => $diffCases,
        'apps_packages' => $apps['packages'],
        'apps_ready' => $apps['ready'],
        'vm_driver_probe_ms_target' => 20,
        'ci_green_streak_days' => (int) $streak['ci_green_streak_days'],
        'last_green_master_sha' => (string) $streak['last_green_master_sha'],
        'notes' => [
            'm3_helloworld' => 'emit_path=native via gen-0 argv helper (#22178); BOOTSTRAP_M3_REQUIRE_NATIVE_EMIT refuses sidecar COPY (#21860/#36146)',
            'm4_honesty' => 'BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT=1 refuses sidecar COPY (#36146); default bootstrap-loop-probe may exit 2 when degraded',
            'm5_fast' => 'Daily gate: make north-star5-verify-fast (do not claim pass/fail without a run receipt)',
            'm5_strict' => 'make north-star5-verify ARGS=--strict before merging bootstrap/gen-0/vendor work',
            'ci' => 'Remote GitHub Actions are not the merge gate; verify with Docker scripts on disk',
            'ci_streak' => 'docs/ci-streak.json via script/status/ci-streak.php (#36401); advance only after verified local green',
        ],
    ];
}

/**
 * @return array{rows:int,vm_yes:int,jit_yes:int,aot_yes:int}
 */
function status_snapshot_capability_counts(string $path): array
{
    if (!is_readable($path)) {
        fwrite(STDERR, "status-snapshot: missing {$path}\n");
        exit(1);
    }
    $rows = 0;
    $vm = 0;
    $jit = 0;
    $aot = 0;
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (!str_starts_with($line, '| `')) {
            continue;
        }
        $parts = array_map('trim', explode('|', trim($line, "| \t")));
        if (count($parts) < 4) {
            continue;
        }
        ++$rows;
        if (($parts[1] ?? '') === 'yes') {
            ++$vm;
        }
        if (($parts[2] ?? '') === 'yes') {
            ++$jit;
        }
        if (($parts[3] ?? '') === 'yes') {
            ++$aot;
        }
    }

    return ['rows' => $rows, 'vm_yes' => $vm, 'jit_yes' => $jit, 'aot_yes' => $aot];
}

/**
 * @return array{packages:int,ready:int}
 */
function status_snapshot_apps(string $path): array
{
    if (!is_readable($path)) {
        return ['packages' => 0, 'ready' => 0];
    }
    $doc = json_decode((string) file_get_contents($path), true);
    if (!is_array($doc) || !isset($doc['packages']) || !is_array($doc['packages'])) {
        return ['packages' => 0, 'ready' => 0];
    }
    $ready = 0;
    foreach ($doc['packages'] as $pkg) {
        if (($pkg['status'] ?? '') === 'ready') {
            ++$ready;
        }
    }

    return ['packages' => count($doc['packages']), 'ready' => $ready];
}

function status_snapshot_count_php_files(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            ++$n;
        }
    }

    return $n;
}

/**
 * @param array<string, mixed> $snapshot
 */
function status_snapshot_check(string $root, array $snapshot, string $docsPath): int
{
    if (!is_readable($docsPath)) {
        fwrite(STDERR, "status-snapshot --check: missing {$docsPath} — run php script/status/snapshot.php\n");
        return 1;
    }
    $committed = json_decode((string) file_get_contents($docsPath), true);
    if (!is_array($committed)) {
        fwrite(STDERR, "status-snapshot --check: invalid JSON in {$docsPath}\n");
        return 1;
    }

    $keys = [
        'spine',
        'inventory',
        'builtins_matrix_rows',
        'builtins_vm_yes',
        'builtins_jit_yes',
        'builtins_aot_yes',
        'differential_cases',
        'apps_packages',
        'apps_ready',
        'vm_driver_probe_ms_target',
        'ci_green_streak_days',
    ];
    $fail = 0;
    foreach ($keys as $key) {
        if (!array_key_exists($key, $committed) || !array_key_exists($key, $snapshot)) {
            fwrite(STDERR, "status-snapshot --check: missing key {$key}\n");
            $fail = 1;
            continue;
        }
        if ((int) $committed[$key] !== (int) $snapshot[$key]) {
            fwrite(STDERR, "status-snapshot --check: drift on {$key}: committed={$committed[$key]} live={$snapshot[$key]}\n");
            $fail = 1;
        }
    }

    $readme = (string) file_get_contents($root . '/README.md');
    if (!preg_match('/<!-- status-snapshot:begin -->.*?<!-- status-snapshot:end -->/s', $readme, $m)) {
        fwrite(STDERR, "status-snapshot --check: README missing status-snapshot markers (#36395)\n");
        return 1;
    }
    $region = $m[0];
    $allowed = [];
    foreach ($keys as $key) {
        $allowed[(string) (int) $snapshot[$key]] = true;
    }

    $scrubbed = preg_replace('#https?://[^\s)\]]+#', '', $region) ?? $region;
    $scrubbed = preg_replace('/#\d+/', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('/\b20\d{2}-\d{2}-\d{2}\b/', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('/\b000\s*[–-]\s*009\b/u', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('/\b\d{3}-[A-Za-z][\w-]*\b/', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('/\bdocker-build-\d+\b/', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('/\bphp-compiler:\d+\.\d+-dev\b/', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('/\bPHP\s*8\.\d\b/i', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('/\bLLVM\s*\d+\b/i', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('/\bv?\d+\.\d+\.\d+\b/', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('/\bexit\s*\*?\*?2\*?\*?\b/i', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('#docs/adr/\d+[-\w.]*#', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('/\bphp-\d+\b/i', '', $scrubbed) ?? $scrubbed;
    $scrubbed = preg_replace('/\bv2\.0\b/', '', $scrubbed) ?? $scrubbed;

    if (preg_match_all('/\b(\d{2,})\b/', $scrubbed, $nums)) {
        foreach (array_unique($nums[1]) as $num) {
            if (!isset($allowed[$num])) {
                fwrite(STDERR, "status-snapshot --check: README snapshot number {$num} not in status JSON (#36395)\n");
                $fail = 1;
            }
        }
    }

    foreach (['spine', 'inventory', 'builtins_matrix_rows', 'differential_cases'] as $requiredKey) {
        $need = (string) (int) $snapshot[$requiredKey];
        if (!str_contains($region, $need)) {
            fwrite(STDERR, "status-snapshot --check: README snapshot missing required metric {$requiredKey}={$need}\n");
            $fail = 1;
        }
    }

    foreach (['1555', '7.7×', '7.7x', '14.8 MB', 'blob copy', 'blob **COPY**', '~65%', 'strict` red', 'strict red', '7410/7412'] as $bad) {
        if (stripos($region, $bad) !== false) {
            fwrite(STDERR, "status-snapshot --check: stale claim " . json_encode($bad) . " still in README snapshot (#36395)\n");
            $fail = 1;
        }
    }

    $index = $root . '/docs/index.md';
    if (!is_readable($index)) {
        fwrite(STDERR, "status-snapshot --check: missing docs/index.md\n");
        $fail = 1;
    } else {
        $body = (string) file_get_contents($index);
        foreach ([
            'Getting started',
            'Language support',
            'Stdlib support',
            'Deploy',
            'Performance',
            'Internals',
            'Contributing for agents',
        ] as $section) {
            if (!str_contains($body, $section)) {
                fwrite(STDERR, "status-snapshot --check: docs/index.md missing section {$section}\n");
                $fail = 1;
            }
        }
        if (!str_contains($body, 'generated by `script/status/snapshot.php`')
            && !str_contains($body, 'generated by `script/render-status-docs.php`')) {
            fwrite(STDERR, "status-snapshot --check: docs/index.md missing generator attribution\n");
            $fail = 1;
        }
    }

    if ($fail === 0) {
        fwrite(STDOUT, "status-snapshot --check: OK\n");
    }

    return $fail;
}

/**
 * @param array<string, mixed> $snapshot
 */
function render_status_docs(string $root, array $snapshot): void
{
    $spine = (int) $snapshot['spine'];
    $inventory = (int) $snapshot['inventory'];
    $builtins = (int) $snapshot['builtins_matrix_rows'];
    $vmYes = (int) $snapshot['builtins_vm_yes'];
    $jitYes = (int) $snapshot['builtins_jit_yes'];
    $aotYes = (int) $snapshot['builtins_aot_yes'];
    $diff = (int) $snapshot['differential_cases'];
    $apps = (int) $snapshot['apps_packages'];
    $appsReady = (int) $snapshot['apps_ready'];
    $probeMs = (int) $snapshot['vm_driver_probe_ms_target'];
    $streakDays = (int) ($snapshot['ci_green_streak_days'] ?? 0);
    $when = (string) ($snapshot['generated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
    $day = substr($when, 0, 10);

    $readmeBlock = <<<MD
<!-- status-snapshot:begin -->
<!-- generated by script/status/snapshot.php from docs/status-snapshot.json (#36395) — do not hand-edit -->
**Snapshot ({$day}, from [`docs/status-snapshot.json`](docs/status-snapshot.json)):** self-host spine **{$spine}** / **{$inventory}** · capability matrix **{$builtins}** rows (VM yes **{$vmYes}**, JIT **{$jitYes}**, AOT **{$aotYes}**) · differential cases **{$diff}** · apps corpus **{$appsReady}**/**{$apps}** ready · VM driver probe target ~**{$probeMs}**ms · local CI streak **{$streakDays}**d ([#36401](https://github.com/PurHur/php-compiler/issues/36401))

## Current implementation status

| Area | State | Notes |
|------|--------|--------|
| **VM (`phpc run`)** | ✅ Production-shaped for dev/CI | Broadest language coverage; reference executor and JIT/AOT fallback |
| **AOT (`phpc build`)** | ✅ For curated subset | Standalone binaries for examples **000–009** and small CGI apps; Composer stacks tracked in [#36382](https://github.com/PurHur/php-compiler/issues/36382) / [#36380](https://github.com/PurHur/php-compiler/issues/36380) |
| **JIT (`bin/jit.php`)** | 🚧 Partial | LLVM IR for many constructs; **MCJIT execute** still flaky ([#98](https://github.com/PurHur/php-compiler/issues/98)) |
| **Self-host north star** | 🚧 | Spine **{$spine}** / **{$inventory}** ✅ · M3 HelloWorld `emit_path=native` via gen-0 argv helper ([#22178](https://github.com/PurHur/php-compiler/issues/22178)) · `BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT=1` refuses sidecar COPY ([#36146](https://github.com/PurHur/php-compiler/issues/36146)) · daily `make north-star5-verify-fast` · `--strict` only before bootstrap merges |

### What you can rely on today

- **`phpc` CLI** — `run`, `serve`, `build`, `deploy`, `lint`, `test`, `init`, `doctor` on the **web-capable PHP 8.3 subset** (v2.0 language baseline — [ADR #36384](docs/adr/36384-php-83-baseline.md); matrix in [`docs/capabilities-syntax.md`](docs/capabilities-syntax.md)).
- **Examples 000–009** — VM smoke via `./phpc test --fast`; AOT when LLVM 9 is present (`./script/aot-smoke.sh`).
- **003-MiniWebApp** — router, templates, forms, JSON API on supported routes.
- **Local/Docker CI** — merge gates run on the host or in `php-compiler:22.04-dev` (remote GitHub Actions are **not** the merge gate on this fork).

### Self-host ladder (experimental)

Counts from `php script/bootstrap-spine-count.php` / `docs/status-snapshot.json`.

| Milestone | Status | What it means |
|-----------|--------|----------------|
| **M0–M1** | ✅ | `compiler_minimal` + compile-smoke bundles link and run natively |
| **M2** | ✅ **{$spine}** / **{$inventory}** | Full Phase A inventory in spine smoke |
| **M3** | ✅ / 🚧 | HelloWorld `emit_path=native` via gen-0 argv helper ([#22178](https://github.com/PurHur/php-compiler/issues/22178)); `BOOTSTRAP_M3_REQUIRE_NATIVE_EMIT=1` refuses sidecar COPY ([#21860](https://github.com/PurHur/php-compiler/issues/21860), [#36146](https://github.com/PurHur/php-compiler/issues/36146)) |
| **M4** | 🚧 | `BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT=1` refuses sidecar COPY ([#36146](https://github.com/PurHur/php-compiler/issues/36146)); default `bootstrap-loop-probe` may exit **2** when the ladder is degraded — not a false OK |
| **M5** | 🚧 | Daily: `make north-star5-verify-fast` · before bootstrap merges: `make north-star5-verify ARGS=--strict` |

**Reproduce M0 smoke on a clean clone:**

```bash
make docker-build-22   # once
./script/docker-exec.sh -- bash -lc 'composer install --ignore-platform-reqs -q && script/apply-patches.sh && make bootstrap-selfhost-link'
./build/selfhost   # -> compiler_minimal bundle OK
```

Deeper ladder: [`docs/bootstrap-selfhost.md`](docs/bootstrap-selfhost.md) · [`docs/bootstrap-m5-fast-path.md`](docs/bootstrap-m5-fast-path.md) · [`docs/GETTING-STARTED.md`](docs/GETTING-STARTED.md) · `make north-star5-verify-fast` · `BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT=1 make bootstrap-loop-probe`.

**Fast iteration:** `make north-star5-verify-fast` · `make bootstrap-selfhost-vm-driver-execute-probe` (~{$probeMs}ms target) · `php script/bootstrap-inventory.php --check` · `php script/status/snapshot.php --check`.

### Still open (high signal)

- **Not Zend PHP** — no full `ext-*` ecosystem or unmodified Composer apps at AOT runtime yet ([#36380](https://github.com/PurHur/php-compiler/issues/36380), [#36382](https://github.com/PurHur/php-compiler/issues/36382))
- **JIT MCJIT execute** — SIGSEGV in probe ([#98](https://github.com/PurHur/php-compiler/issues/98))
- **Fresh host PHP 8.3+** — use `composer install --ignore-platform-reqs` or Docker

Live matrices: [status page](docs/pages/status.html) · [docs index](docs/index.md) · [gap tables](https://purhur.github.io/php-compiler/docs/pages/missing-implementation.html).
<!-- status-snapshot:end -->
MD;

    $readmePath = $root . '/README.md';
    $readme = (string) file_get_contents($readmePath);
    if (preg_match('/<!-- status-snapshot:begin -->.*?<!-- status-snapshot:end -->/s', $readme)) {
        $readme = preg_replace('/<!-- status-snapshot:begin -->.*?<!-- status-snapshot:end -->/s', trim($readmeBlock), $readme);
    } else {
        $pattern = '/\*\*Snapshot \(.*?\*\*.*?\n---\n\n## Current implementation status.*?\n---\n/s';
        if (!preg_match($pattern, $readme)) {
            fwrite(STDERR, "render-status-docs: could not locate legacy README snapshot region\n");
            exit(1);
        }
        $readme = preg_replace($pattern, trim($readmeBlock) . "\n\n---\n\n", $readme, 1);
    }
    file_put_contents($readmePath, $readme);
    fwrite(STDOUT, "render-status-docs: updated README.md snapshot\n");

    $index = <<<MD
# php-compiler documentation

<!-- generated by `script/status/snapshot.php` from docs/status-snapshot.json (#36395) — do not hand-edit the section list -->

Status numbers: [`docs/status-snapshot.json`](status-snapshot.json) (regenerate with `php script/status/snapshot.php`).

## Getting started

Clone → Composer → patches → smoke. Start at [`GETTING-STARTED.md`](GETTING-STARTED.md) and the root [`README.md`](../README.md).

## Language support

Probed construct matrix: [`capabilities-syntax.md`](capabilities-syntax.md) (`php script/capability-syntax.php`). PHP baseline ADR: [`adr/36384-php-83-baseline.md`](adr/36384-php-83-baseline.md).

## Stdlib support

Builtin matrix: [`capabilities.md`](capabilities.md) (`php script/capability-matrix.php`) — currently **{$builtins}** rows (**{$vmYes}** VM / **{$jitYes}** JIT / **{$aotYes}** AOT yes). Extension manifests: [`extensions.md`](extensions.md).

## Deploy

[`deploy-web-aot.md`](deploy-web-aot.md) · [`deploy-production.md`](deploy-production.md) · [`phpc-json.md`](phpc-json.md). Living demo work: [#36392](https://github.com/PurHur/php-compiler/issues/36392).

## Performance

Bench history page: [`pages/bench.html`](pages/bench.html). Do not quote a ratio that is not in a committed bench table. Roadmap: [#36385](https://github.com/PurHur/php-compiler/issues/36385) / [#36386](https://github.com/PurHur/php-compiler/issues/36386).

## Internals

[`architecture-review-2026-07.md`](architecture-review-2026-07.md) · [`self-host-target.md`](self-host-target.md) · [`bootstrap-m5-fast-path.md`](bootstrap-m5-fast-path.md) · ADRs under [`adr/`](adr/). Spine coverage **{$spine}** / **{$inventory}**.

## Contributing for agents

[`../AGENTS.md`](../AGENTS.md) — Trust > Ship > Self-host > Correctness > Velocity; never restamp gen-0; name the local gate you ran (remote `CLEAN` is not evidence). Definition of Done: [#36400](https://github.com/PurHur/php-compiler/issues/36400).
MD;
    file_put_contents($root . '/docs/index.md', $index);
    fwrite(STDOUT, "render-status-docs: wrote docs/index.md\n");

    $statusHtml = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>php-compiler status snapshot</title>
  <meta name="generator" content="script/status/snapshot.php">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
  <main class="content" style="max-width:52rem;margin:2rem auto;padding:0 1rem">
    <p><a href="index.html">← Overview</a></p>
    <h1>Status snapshot</h1>
    <p>Generated by <code>script/status/snapshot.php</code> from <code>docs/status-snapshot.json</code> (#36395) at <time datetime="{$when}">{$when}</time>.</p>
    <table>
      <thead><tr><th>Metric</th><th>Value</th></tr></thead>
      <tbody>
        <tr><td>Spine / inventory</td><td><strong>{$spine}</strong> / <strong>{$inventory}</strong></td></tr>
        <tr><td>Capability matrix rows</td><td>{$builtins} (VM {$vmYes} / JIT {$jitYes} / AOT {$aotYes})</td></tr>
        <tr><td>Differential cases</td><td>{$diff}</td></tr>
        <tr><td>Apps corpus ready</td><td>{$appsReady} / {$apps}</td></tr>
        <tr><td>VM driver probe target</td><td>~{$probeMs} ms</td></tr>
      </tbody>
    </table>
    <p>Numbers are derived from committed artifacts only. Gate pass/fail is not stamped here — run <code>make north-star5-verify-fast</code> locally.</p>
    <p>Weekly maintainer digest: <a href="status/latest.md">docs/pages/status/latest.md</a> via <code>make status-report</code> (#36404).</p>
  </main>
</body>
</html>
HTML;
    file_put_contents($root . '/docs/pages/status.html', $statusHtml);
    fwrite(STDOUT, "render-status-docs: wrote docs/pages/status.html\n");
}
