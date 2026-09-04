<?php

declare(strict_types=1);

/**
 * Run ready apps in test/apps/, refresh SCOREBOARD.json, regenerate apps.html (#36380).
 *
 * Usage:
 *   php script/apps/scoreboard.php              # run + write
 *   php script/apps/scoreboard.php --check      # ratchet only (no run)
 *   php script/apps/scoreboard.php --slug=erusev-parsedown
 *   ./script/docker-exec.sh -- bash -lc 'php script/apps/scoreboard.php'
 */

$root = dirname(__DIR__, 2);
$appsDir = $root . '/test/apps';
$manifestPath = $appsDir . '/MANIFEST.json';
$scoreboardPath = $appsDir . '/SCOREBOARD.json';

$checkOnly = in_array('--check', $argv, true);
$slugFilter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--slug=')) {
        $slugFilter = substr($arg, 7);
    }
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest) || !isset($manifest['packages']) || !is_array($manifest['packages'])) {
    fwrite(STDERR, "apps-scoreboard: invalid MANIFEST.json\n");
    exit(1);
}

if ($checkOnly) {
    exit(checkRatchet($scoreboardPath));
}

$prev = [];
if (is_file($scoreboardPath)) {
    $prevDoc = json_decode((string) file_get_contents($scoreboardPath), true);
    if (is_array($prevDoc) && isset($prevDoc['packages']) && is_array($prevDoc['packages'])) {
        foreach ($prevDoc['packages'] as $row) {
            if (isset($row['slug'])) {
                $prev[$row['slug']] = $row;
            }
        }
    }
}

$packages = [];
foreach ($manifest['packages'] as $pkg) {
    $slug = $pkg['slug'] ?? '';
    if ($slug === '') {
        continue;
    }
    if (null !== $slugFilter && $slug !== $slugFilter) {
        // Keep prior row when filtering.
        if (isset($prev[$slug])) {
            $packages[] = $prev[$slug];
        } else {
            $packages[] = pendingRow($pkg);
        }
        continue;
    }

    if (($pkg['status'] ?? '') !== 'ready') {
        $packages[] = pendingRow($pkg);
        continue;
    }

    $runSh = $appsDir . '/' . $slug . '/run.sh';
    if (!is_file($runSh)) {
        $packages[] = array_merge(pendingRow($pkg), [
            'status' => 'missing_runner',
            'blockers' => ['run.sh missing'],
        ]);
        continue;
    }

    $cmd = 'bash ' . escapeshellarg($runSh);
    $out = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    $text = implode("\n", $out);
    $backends = parseResults($text);
    $packages[] = [
        'slug' => $slug,
        'composer' => $pkg['composer'] ?? $slug,
        'sha' => $pkg['sha'] ?? null,
        'status' => 'ready',
        'zend' => $backends['zend'] ?? emptyBackend(),
        'vm' => $backends['vm'] ?? emptyBackend(),
        'aot' => $backends['aot'] ?? emptyBackend(),
        'aot_pass_rate' => passRate($backends['aot'] ?? null),
        'blockers' => collectBlockers($backends),
        'raw_rc' => $rc,
    ];
}

$doc = [
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'issue' => 36380,
    'packages' => $packages,
];

$json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (false === file_put_contents($scoreboardPath, $json)) {
    fwrite(STDERR, "apps-scoreboard: failed to write SCOREBOARD.json\n");
    exit(1);
}
echo "apps-scoreboard: wrote $scoreboardPath (" . count($packages) . " packages)\n";

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/script/apps/generate-page.php'), $pageRc);
if ($pageRc !== 0) {
    exit($pageRc);
}

// Ratchet against the just-written board (first run establishes baseline).
exit(checkRatchet($scoreboardPath, $prev));

function pendingRow(array $pkg): array
{
    return [
        'slug' => $pkg['slug'],
        'composer' => $pkg['composer'] ?? $pkg['slug'],
        'sha' => $pkg['sha'] ?? null,
        'status' => $pkg['status'] ?? 'pending',
        'zend' => emptyBackend(),
        'vm' => emptyBackend(),
        'aot' => emptyBackend(),
        'aot_pass_rate' => null,
        'blockers' => ['pending pin'],
    ];
}

function emptyBackend(): array
{
    return ['status' => 'pending', 'pass' => 0, 'fail' => 0, 'skip' => 0, 'rc' => null, 'reason' => null];
}

function parseResults(string $text): array
{
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        if (!str_starts_with($line, 'RESULT ')) {
            continue;
        }
        $fields = [];
        foreach (explode(' ', substr($line, 7)) as $tok) {
            if (!str_contains($tok, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $tok, 2);
            $fields[$k] = $v;
        }
        $backend = $fields['backend'] ?? '';
        if ($backend === '') {
            continue;
        }
        $out[$backend] = [
            'status' => $fields['status'] ?? 'unknown',
            'pass' => (int) ($fields['pass'] ?? 0),
            'fail' => (int) ($fields['fail'] ?? 0),
            'skip' => (int) ($fields['skip'] ?? 0),
            'rc' => isset($fields['rc']) ? (int) $fields['rc'] : null,
            'reason' => $fields['reason'] ?? null,
        ];
    }
    return $out;
}

function passRate(?array $backend): ?float
{
    if ($backend === null) {
        return null;
    }
    if (($backend['status'] ?? '') === 'block' || ($backend['status'] ?? '') === 'pending') {
        return 0.0;
    }
    $pass = (int) ($backend['pass'] ?? 0);
    $fail = (int) ($backend['fail'] ?? 0);
    $den = $pass + $fail;
    if ($den === 0) {
        return 0.0;
    }
    return round(100.0 * $pass / $den, 2);
}

function collectBlockers(array $backends): array
{
    $b = [];
    foreach (['zend', 'vm', 'aot'] as $name) {
        $row = $backends[$name] ?? null;
        if ($row === null) {
            continue;
        }
        if (($row['status'] ?? '') === 'block' && !empty($row['reason'])) {
            $b[] = $name . ': ' . $row['reason'];
        } elseif (($row['status'] ?? '') === 'fail' && (int) ($row['fail'] ?? 0) > 0) {
            $b[] = $name . ': ' . $row['fail'] . ' fixture failures';
        }
    }
    return $b;
}

/**
 * @param array<string,array> $prevBySlug
 */
function checkRatchet(string $scoreboardPath, array $prevBySlug = []): int
{
    if (!is_file($scoreboardPath)) {
        fwrite(STDERR, "apps-scoreboard --check: SCOREBOARD.json missing\n");
        return 1;
    }
    $doc = json_decode((string) file_get_contents($scoreboardPath), true);
    if (!is_array($doc) || !isset($doc['packages']) || !is_array($doc['packages'])) {
        fwrite(STDERR, "apps-scoreboard --check: invalid SCOREBOARD.json\n");
        return 1;
    }
    if ($doc['packages'] === []) {
        fwrite(STDERR, "apps-scoreboard --check: empty package list (not a pass)\n");
        return 1;
    }

    // When --check with no prior passed in, load committed file as both current+baseline.
    if ($prevBySlug === []) {
        echo "apps-scoreboard --check: OK (" . count($doc['packages']) . " packages; baseline established)\n";
        return 0;
    }

    $regressions = [];
    foreach ($doc['packages'] as $row) {
        $slug = $row['slug'] ?? '';
        if ($slug === '' || !isset($prevBySlug[$slug])) {
            continue;
        }
        $prevRate = $prevBySlug[$slug]['aot_pass_rate'] ?? null;
        $curRate = $row['aot_pass_rate'] ?? null;
        if ($prevRate === null || $curRate === null) {
            continue;
        }
        if ((float) $curRate + 0.001 < (float) $prevRate) {
            $regressions[] = sprintf('%s AOT %.2f%% → %.2f%%', $slug, $prevRate, $curRate);
        }
    }
    if ($regressions !== []) {
        fwrite(STDERR, "apps-scoreboard --check: AOT pass-rate regression:\n  - " . implode("\n  - ", $regressions) . "\n");
        return 1;
    }
    echo "apps-scoreboard --check: OK (no AOT pass-rate regressions)\n";
    return 0;
}
