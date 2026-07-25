#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * How far the committed gen-0 seed has drifted from the sources it claims (#22642).
 *
 * The manifest's `lowering_source_fingerprint` is a local claim: a stamp can be written
 * without rebuilding anything, and for a long stretch of this repo's history that is exactly
 * what happened — the committed driver bytes last moved in June while the manifest was
 * restamped hundreds of times, and release-readiness went green on every one.
 *
 * This check does not trust the manifest. It reads git history:
 *
 *   - when prelinked/bootstrap-gen0/bin-compile-aot bytes last changed
 *   - how many commits since then touched lowering sources (lib/, ext/, patches/,
 *     composer.lock, script/apply-patches.sh, bin/compile.php)
 *   - how many commits since then touched only the manifest (restamps)
 *
 * A seed whose bytes predate lowering-source commits was not built from current sources,
 * whatever the stamp says.
 *
 * Usage:
 *   php script/bootstrap-gen0-staleness.php           # human summary
 *   php script/bootstrap-gen0-staleness.php --json    # machine block
 *
 * Exit codes: 0 fresh (or indeterminate), 1 stale, 2 not a git checkout.
 */

$root = dirname(__DIR__);
$json = in_array('--json', $argv, true);

/** @return array{ok: bool, out: string} */
function gen0_git(string $root, string ...$args): array
{
    $cmd = 'git -C '.escapeshellarg($root);
    foreach ($args as $arg) {
        $cmd .= ' '.escapeshellarg($arg);
    }
    $out = @shell_exec($cmd.' 2>/dev/null');

    return ['ok' => is_string($out), 'out' => is_string($out) ? trim($out) : ''];
}

/** Lowering-source path specs, matching bootstrap_lowering_source_paths() coverage. */
function gen0_lowering_pathspecs(): array
{
    return ['lib', 'ext', 'patches', 'composer.lock', 'script/apply-patches.sh', 'bin/compile.php'];
}

const GEN0_DRIVER_REL = 'prelinked/bootstrap-gen0/bin-compile-aot';
const GEN0_MANIFEST_REL = 'prelinked/bootstrap-gen0/manifest.json';

$inRepo = gen0_git($root, 'rev-parse', '--is-inside-work-tree');
if (!$inRepo['ok'] || 'true' !== $inRepo['out']) {
    $result = [
        'status' => 'unknown',
        'message' => 'not a git checkout — cannot derive gen-0 seed age',
    ];
    echo $json ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        : "bootstrap-gen0-staleness: unknown (not a git checkout)\n";
    exit(2);
}

$lastBuild = gen0_git($root, 'log', '-1', '--format=%H %cs', '--', GEN0_DRIVER_REL);
if ('' === $lastBuild['out']) {
    $result = [
        'status' => 'unknown',
        'message' => 'no commit found touching '.GEN0_DRIVER_REL,
    ];
    echo $json ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        : "bootstrap-gen0-staleness: unknown (no history for driver)\n";
    exit(2);
}

[$buildSha, $buildDate] = array_pad(explode(' ', $lastBuild['out'], 2), 2, '');

$countSince = static function (string $root, string $sha, array $pathspecs): int {
    $args = ['rev-list', '--count', $sha.'..HEAD', '--'];
    foreach ($pathspecs as $spec) {
        $args[] = $spec;
    }
    $res = gen0_git($root, ...$args);

    return ctype_digit($res['out']) ? (int) $res['out'] : 0;
};

$loweringCommits = $countSince($root, $buildSha, gen0_lowering_pathspecs());
$manifestCommits = $countSince($root, $buildSha, [GEN0_MANIFEST_REL]);

$ageDays = null;
$buildTs = gen0_git($root, 'log', '-1', '--format=%ct', $buildSha);
if (ctype_digit($buildTs['out'])) {
    $ageDays = (int) floor((time() - (int) $buildTs['out']) / 86400);
}

$manifest = null;
$manifestPath = $root.'/'.GEN0_MANIFEST_REL;
if (is_readable($manifestPath)) {
    $decoded = json_decode((string) file_get_contents($manifestPath), true);
    $manifest = is_array($decoded) ? $decoded : null;
}
$provenance = is_array($manifest) ? trim((string) ($manifest['provenance'] ?? '')) : '';

$stale = $loweringCommits > 0;
$result = [
    'status' => $stale ? 'stale' : 'fresh',
    'driver_last_built' => ['commit' => substr($buildSha, 0, 9), 'date' => $buildDate, 'age_days' => $ageDays],
    'lowering_commits_since' => $loweringCommits,
    'manifest_commits_since' => $manifestCommits,
    'manifest_provenance' => '' === $provenance ? 'unrecorded' : $provenance,
    'message' => $stale
        ? sprintf(
            'committed gen-0 driver bytes are from %s (%s, %s days ago); %d commit(s) have changed lowering sources since, and the manifest was rewritten %d time(s) — the seed was not built from current sources',
            substr($buildSha, 0, 9),
            $buildDate,
            null === $ageDays ? '?' : (string) $ageDays,
            $loweringCommits,
            $manifestCommits
        )
        : sprintf('committed gen-0 driver bytes (%s, %s) are current with lowering sources', substr($buildSha, 0, 9), $buildDate),
];

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} else {
    echo 'bootstrap-gen0-staleness: '.$result['status'].' — '.$result['message']."\n";
    echo '  manifest provenance: '.$result['manifest_provenance']."\n";
}

exit($stale ? 1 : 0);
