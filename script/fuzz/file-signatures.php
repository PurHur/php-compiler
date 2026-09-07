#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * File distinct differential-fuzz signatures as GitHub issues (#36398).
 *
 * Reads unique failure JSON (+ optional reduced PHP) from a fuzz keep-failures
 * directory, writes house-format issue bodies, and optionally creates issues via
 * `gh` when --create is set. Dedups against a committed registry so re-runs do
 * not spam the tracker.
 *
 * Usage:
 *   php script/fuzz/file-signatures.php --failures-dir DIR [--reduced-dir DIR]
 *       [--outdir DIR] [--registry PATH] [--create] [--dry-run] [--limit N]
 */

require __DIR__.'/lib.php';

$opts = getopt('', [
    'failures-dir:',
    'reduced-dir:',
    'outdir:',
    'registry:',
    'create',
    'dry-run',
    'limit:',
    'repo:',
    'help',
]);

if (isset($opts['help']) || !isset($opts['failures-dir'])) {
    fwrite(STDERR, <<<'USAGE'
Usage: php script/fuzz/file-signatures.php --failures-dir DIR [options]

Options:
  --reduced-dir DIR   Prefer reduced reproducers from this dir (basename match)
  --outdir DIR        Write markdown bodies here (default: DIR/../issue-drafts)
  --registry PATH     Filed-signature registry JSON (default: test/differential/cases/fuzz/SIGNATURES.json)
  --create            Run `gh issue create` for new signatures
  --dry-run           Print actions only; do not write registry or create issues
  --limit N           Cap how many new signatures to process (default: 20)
  --repo OWNER/NAME   GitHub repo (default: PurHur/php-compiler)

USAGE);
    exit(isset($opts['help']) ? 0 : 2);
}

$root = fuzz_repo_root();
$failuresDir = fuzz_resolve_path((string) $opts['failures-dir'], $root);
$reducedDir = isset($opts['reduced-dir'])
    ? fuzz_resolve_path((string) $opts['reduced-dir'], $root)
    : null;
$outdir = isset($opts['outdir'])
    ? fuzz_resolve_path((string) $opts['outdir'], $root)
    : dirname($failuresDir).'/issue-drafts';
$registryPath = isset($opts['registry'])
    ? fuzz_resolve_path((string) $opts['registry'], $root)
    : $root.'/test/differential/cases/fuzz/SIGNATURES.json';
$doCreate = isset($opts['create']);
$dryRun = isset($opts['dry-run']);
$limit = isset($opts['limit']) ? max(0, (int) $opts['limit']) : 20;
$repo = isset($opts['repo']) ? (string) $opts['repo'] : 'PurHur/php-compiler';

if (!is_dir($failuresDir)) {
    fwrite(STDERR, "fuzz/file-signatures: failures dir missing: {$failuresDir}\n");
    exit(2);
}

$registry = fuzz_load_signature_registry($registryPath);
$jsonFiles = glob($failuresDir.'/*.json') ?: [];
sort($jsonFiles);

$processed = 0;
$skippedKnown = 0;
$created = 0;
$drafted = 0;
$results = [];

if (!$dryRun && !is_dir($outdir)) {
    mkdir($outdir, 0777, true);
}

foreach ($jsonFiles as $jsonPath) {
    if ($processed >= $limit) {
        break;
    }
    $meta = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($meta) || !isset($meta['signature'], $meta['kind'], $meta['seed'])) {
        fwrite(STDERR, "fuzz/file-signatures: skip malformed {$jsonPath}\n");
        continue;
    }
    $sig = (string) $meta['signature'];
    if (isset($registry['signatures'][$sig])) {
        ++$skippedKnown;
        continue;
    }

    $base = basename($jsonPath, '.json');
    $phpPath = $failuresDir.'/'.$base.'.php';
    $reducedPath = $reducedDir !== null ? $reducedDir.'/'.$base.'.php' : null;
    $reproPath = ($reducedPath !== null && is_readable($reducedPath)) ? $reducedPath : $phpPath;
    if (!is_readable($reproPath)) {
        fwrite(STDERR, "fuzz/file-signatures: missing repro for {$base}\n");
        continue;
    }

    $reproSrc = (string) file_get_contents($reproPath);
    $nonempty = fuzz_count_nonempty_lines($reproSrc);
    $backend = fuzz_backend_from_kind((string) $meta['kind']);
    $draft = fuzz_build_signature_issue(
        $meta,
        $reproSrc,
        $nonempty,
        $backend,
        $sig,
        basename($reproPath)
    );

    $mdPath = $outdir.'/'.$base.'.md';
    $title = $draft['title'];
    $body = $draft['body'];

    if ($dryRun) {
        echo "DRY-RUN would file: {$title} (sig=".substr($sig, 0, 12)."… nonempty={$nonempty})\n";
        $results[] = [
            'signature' => $sig,
            'title' => $title,
            'action' => 'dry-run',
            'nonempty_lines' => $nonempty,
        ];
        ++$processed;
        ++$drafted;
        continue;
    }

    file_put_contents($mdPath, "# {$title}\n\n{$body}");
    ++$drafted;

    $issueUrl = null;
    $issueNumber = null;
    if ($doCreate) {
        $tmpBody = tempnam(sys_get_temp_dir(), 'fuzziss');
        if ($tmpBody === false) {
            fwrite(STDERR, "fuzz/file-signatures: tempnam failed\n");
            exit(2);
        }
        file_put_contents($tmpBody, $body);
        $cmd = 'gh issue create --repo '.escapeshellarg($repo)
            .' --title '.escapeshellarg($title)
            .' --body-file '.escapeshellarg($tmpBody)
            .' --label '.escapeshellarg('bug')
            .' --label '.escapeshellarg('area:compiler')
            .' --label '.escapeshellarg('phase-2:language')
            .' --label '.escapeshellarg('implementation-ready');
        $outLines = [];
        $rc = 0;
        exec($cmd.' 2>&1', $outLines, $rc);
        @unlink($tmpBody);
        $joined = implode("\n", $outLines);
        if ($rc !== 0) {
            fwrite(STDERR, "fuzz/file-signatures: gh failed for {$base}: {$joined}\n");
            $results[] = [
                'signature' => $sig,
                'title' => $title,
                'action' => 'gh-failed',
                'error' => $joined,
            ];
            ++$processed;
            continue;
        }
        $issueUrl = trim($joined);
        if (preg_match('#/issues/(\d+)#', $issueUrl, $m)) {
            $issueNumber = (int) $m[1];
        }
        ++$created;
        echo "filed {$issueUrl} — {$title}\n";
    } else {
        echo "drafted {$mdPath} — {$title}\n";
    }

    $registry['signatures'][$sig] = [
        'seed' => (int) $meta['seed'],
        'kind' => (string) $meta['kind'],
        'nonempty_lines' => $nonempty,
        'draft' => $mdPath,
        'issue' => $issueNumber,
        'url' => $issueUrl,
        'filed_at' => gmdate('c'),
        'parent' => 36398,
    ];
    $results[] = [
        'signature' => $sig,
        'title' => $title,
        'action' => $doCreate ? 'created' : 'drafted',
        'issue' => $issueNumber,
        'url' => $issueUrl,
        'nonempty_lines' => $nonempty,
    ];
    ++$processed;
}

if (!$dryRun) {
    $registry['updated_at'] = gmdate('c');
    $registry['parent_issue'] = 36398;
    ksort($registry['signatures']);
    fuzz_write_signature_registry($registryPath, $registry);
}

$summary = [
    'processed' => $processed,
    'drafted' => $drafted,
    'created' => $created,
    'skipped_known' => $skippedKnown,
    'registry' => $registryPath,
    'outdir' => $outdir,
    'results' => $results,
];
echo json_encode($summary, JSON_PRETTY_PRINT)."\n";
exit(0);

function fuzz_resolve_path(string $path, string $root): string
{
    if ($path !== '' && $path[0] === '/') {
        return $path;
    }

    return $root.'/'.$path;
}

/** @return array{signatures: array<string, mixed>, updated_at?: string, parent_issue?: int} */
function fuzz_load_signature_registry(string $path): array
{
    if (!is_readable($path)) {
        return ['signatures' => [], 'parent_issue' => 36398];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return ['signatures' => [], 'parent_issue' => 36398];
    }
    if (!isset($data['signatures']) || !is_array($data['signatures'])) {
        $data['signatures'] = [];
    }

    return $data;
}

/** @param array{signatures: array<string, mixed>} $registry */
function fuzz_write_signature_registry(string $path, array $registry): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

function fuzz_backend_from_kind(string $kind): string
{
    if (str_starts_with($kind, 'aot')) {
        return 'aot';
    }

    return 'vm';
}

/**
 * @param array<string, mixed> $meta
 * @return array{title: string, body: string}
 */
function fuzz_build_signature_issue(
    array $meta,
    string $reproSrc,
    int $nonempty,
    string $backend,
    string $sig,
    string $reproName
): array {
    $kind = (string) $meta['kind'];
    $seed = (int) $meta['seed'];
    $zendRc = (int) ($meta['zend_rc'] ?? -1);
    $gotRc = (int) ($meta['got_rc'] ?? -1);
    $zendOut = fuzz_collapse_output((string) ($meta['zend_out'] ?? ''));
    $gotOut = fuzz_collapse_output((string) ($meta['got_out'] ?? ''));

    $symptom = match (true) {
        str_contains($kind, 'crash') => 'crashes vs Zend',
        str_contains($kind, 'timeout') => 'times out vs Zend',
        default => 'wrong output vs Zend',
    };
    $shortSig = substr($sig, 0, 12);
    $title = sprintf(
        'Fuzz: %s on %s (seed %d, sig %s…) — differential mismatch (script/fuzz/, re-#36398)',
        $symptom,
        strtoupper($backend),
        $seed,
        $shortSig
    );

    $zendSnippet = fuzz_issue_truncate($zendOut, 800);
    $gotSnippet = fuzz_issue_truncate($gotOut, 800);
    $reproFenced = fuzz_issue_truncate($reproSrc, 4000);

    $body = <<<MD
## Category
`Regression:` · differential fuzz finding · child of #36398

## Problem
Grammar-generated program (seed **{$seed}**, kind `{$kind}`) disagrees with Zend under **{$backend}**.

| | Zend | {$backend} |
|---|---|---|
| exit | `{$zendRc}` | `{$gotRc}` |

**Zend output (collapsed):**

```
{$zendSnippet}
```

**{$backend} output (collapsed):**

```
{$gotSnippet}
```

- Signature: `{$sig}`
- Reduced repro nonempty lines: **{$nonempty}** (target ≤ 15)
- Source file name: `{$reproName}`

## php-src reference
- Zend is the oracle — compare via `php` vs `php bin/vm.php` / AOT emit
- Narrow the failing opcode / builtin after reduce; cite the php-src handler in the fix PR

## PHP implementation target
- Prefer PHP lowering in `lib/` / `ext/` / `lib/JIT/` — no new `runtime/*.c`
- Attach the reduced program under `test/differential/cases/fuzz/` once fixed

## Repro
```bash
./script/docker-exec.sh -- bash -lc 'php script/fuzz/gen.php --seed {$seed} --out /tmp/fuzz-{$seed}.php'
./script/docker-exec.sh -- bash -lc 'php bin/vm.php /tmp/fuzz-{$seed}.php; echo exit:\$?'
./script/docker-exec.sh -- bash -lc 'php /tmp/fuzz-{$seed}.php; echo exit:\$?'
```

Reduced program:

```php
{$reproFenced}
```

## Done when
- [ ] VM and/or AOT match Zend on the reduced program (stdout + stderr + exit)
- [ ] Reduced case promoted to `test/differential/cases/fuzz/` and COUNT updated
- [ ] Signature remains in `test/differential/cases/fuzz/SIGNATURES.json` with the fix issue/PR link

## Parent
Part of #36398 · filed by `script/fuzz/file-signatures.php`
MD;

    return ['title' => $title, 'body' => $body];
}

function fuzz_issue_truncate(string $s, int $max): string
{
    $s = str_replace("\r\n", "\n", $s);
    if (strlen($s) <= $max) {
        return $s;
    }

    return substr($s, 0, $max)."\n…(truncated)…\n";
}
