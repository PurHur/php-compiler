<?php

declare(strict_types=1);

/**
 * Combine emitted gen-0 chunk objects and optionally executable-link them (#36387).
 *
 * Driven by script/bootstrap-gen0-chunk-link.sh. Reads CHUNK_OUT_DIR / CHUNK_PLAN /
 * CHUNK_LINK_EXECUTABLE from the environment.
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/lib/AOT/LinkerProcessPolyfill.php';

if (!\function_exists('phpc_run_command')) {
    /**
     * @param array<string, string>|null $env
     *
     * @return array{code:int,stdout:string,stderr:string}|null
     */
    function phpc_run_command(string $command, ?array $env = null): ?array
    {
        return \PHPCompiler\AOT\LinkerProcessPolyfill::run($command, $env);
    }
}

use PHPCompiler\AOT\HelperRuntimeCache;
use PHPCompiler\AOT\Linker;

$outDir = getenv('CHUNK_OUT_DIR') ?: '';
$planPath = getenv('CHUNK_PLAN') ?: '';
$combinedO = getenv('CHUNK_COMBINED_O') ?: '';
$combinedBin = getenv('CHUNK_COMBINED_BIN') ?: '';
$linkExe = getenv('CHUNK_LINK_EXECUTABLE') === '1';
$receiptPath = getenv('CHUNK_LINK_RECEIPT') ?: '';
$wantFp = getenv('CHUNK_LINK_WANT_FP') ?: '';

if ($outDir === '' || $combinedO === '' || $receiptPath === '') {
    fwrite(STDERR, "bootstrap-gen0-chunk-link.php: set CHUNK_OUT_DIR, CHUNK_COMBINED_O, CHUNK_LINK_RECEIPT\n");
    exit(2);
}

/**
 * @return list<string>
 */
$chunkIdsFromPlan = static function (?string $planPath): array {
    if ($planPath === null || $planPath === '' || !is_file($planPath)) {
        return [];
    }
    $plan = json_decode((string) file_get_contents($planPath), true);
    if (!is_array($plan) || !isset($plan['chunks']) || !is_array($plan['chunks'])) {
        return [];
    }
    $ids = [];
    foreach ($plan['chunks'] as $chunk) {
        if (!is_array($chunk)) {
            continue;
        }
        $id = $chunk['chunk_id'] ?? null;
        if (is_string($id) && $id !== '') {
            $ids[] = $id;
        }
    }

    return $ids;
};

/**
 * @param list<string> $preferredIds
 *
 * @return list<array{chunk_id:string,object:string,helpers:?string}>
 */
$collectObjects = static function (string $outDir, array $preferredIds): array {
    $summaryPath = $outDir.'/summary.json';
    $ok = [];
    if (is_file($summaryPath)) {
        $summary = json_decode((string) file_get_contents($summaryPath), true);
        if (is_array($summary) && isset($summary['results']) && is_array($summary['results'])) {
            foreach ($summary['results'] as $id => $status) {
                if (($status === 'OK' || $status === 'SKIP') && is_string($id)) {
                    $ok[$id] = true;
                }
            }
        }
    }

    $ordered = $preferredIds !== [] ? $preferredIds : [];
    if ($ordered === []) {
        foreach (glob($outDir.'/*.receipt.json') ?: [] as $receiptFile) {
            $base = basename($receiptFile, '.receipt.json');
            if ($base !== '' && $base !== 'link') {
                $ordered[] = $base;
            }
        }
        sort($ordered, SORT_STRING);
    }

    $rows = [];
    foreach ($ordered as $id) {
        if ($ok !== [] && !isset($ok[$id])) {
            continue;
        }
        $object = $outDir.'/'.$id.'.o';
        $receipt = $outDir.'/'.$id.'.receipt.json';
        if (!is_file($object) || filesize($object) < 1) {
            continue;
        }
        if (is_file($receipt)) {
            $r = json_decode((string) file_get_contents($receipt), true);
            if (is_array($r) && (int) ($r['exit_code'] ?? 1) !== 0) {
                continue;
            }
            if (is_array($r) && ($r['object_only'] ?? true) === false) {
                // Linked binary was requested for this chunk — skip for combine.
                continue;
            }
        }
        $helpers = $outDir.'/'.$id.'.helpers.json';
        $rows[] = [
            'chunk_id' => $id,
            'object' => $object,
            'helpers' => is_file($helpers) ? $helpers : null,
        ];
    }

    return $rows;
};

/**
 * @param list<array{chunk_id:string,object:string,helpers:?string}> $rows
 *
 * @return list<string>
 */
$unionHelperSlugs = static function (array $rows): array {
    $slugs = [];
    foreach ($rows as $row) {
        $path = $row['helpers'];
        if (!is_string($path) || $path === '' || !is_file($path)) {
            continue;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['helper_slugs']) || !is_array($data['helper_slugs'])) {
            continue;
        }
        foreach ($data['helper_slugs'] as $slug) {
            if (is_string($slug) && $slug !== '') {
                $slugs[$slug] = true;
            }
        }
    }

    return array_keys($slugs);
};

$ids = $chunkIdsFromPlan($planPath !== '' ? $planPath : null);
$rows = $collectObjects($outDir, $ids);
if ($rows === []) {
    fwrite(STDERR, "bootstrap-gen0-chunk-link.php: no OK object-only chunk .o files in {$outDir}\n");
    exit(1);
}

$objects = array_map(static fn (array $r): string => $r['object'], $rows);
fwrite(STDOUT, 'bootstrap-gen0-chunk-link.php: objects='.count($objects)."\n");

if (count($objects) === 1) {
    if (!@copy($objects[0], $combinedO)) {
        fwrite(STDERR, "bootstrap-gen0-chunk-link.php: failed to copy sole object to {$combinedO}\n");
        exit(1);
    }
} else {
    if (!Linker::combineRelocatableObjects($objects, $combinedO)) {
        fwrite(STDERR, "bootstrap-gen0-chunk-link.php: combineRelocatableObjects failed\n");
        exit(1);
    }
}

if (!is_file($combinedO) || filesize($combinedO) < 1) {
    fwrite(STDERR, "bootstrap-gen0-chunk-link.php: missing combined object {$combinedO}\n");
    exit(1);
}

$slugs = $unionHelperSlugs($rows);
$binSize = 0;
$linked = false;

if ($linkExe) {
    if ($combinedBin === '') {
        fwrite(STDERR, "bootstrap-gen0-chunk-link.php: CHUNK_COMBINED_BIN required for executable link\n");
        exit(2);
    }
    putenv('PHP_COMPILER_HELPER_RUNTIME_O=1');
    $_ENV['PHP_COMPILER_HELPER_RUNTIME_O'] = '1';
    HelperRuntimeCache::adoptUnitSlugsForLink($slugs);
    fwrite(STDOUT, 'bootstrap-gen0-chunk-link.php: helper_slugs='.count($slugs)."\n");
    @unlink($combinedBin);
    try {
        Linker::link($combinedO, $combinedBin);
        Linker::assertNonEmptyOutputFile($combinedBin);
    } catch (Throwable $e) {
        fwrite(STDERR, 'bootstrap-gen0-chunk-link.php: executable link failed: '.$e->getMessage()."\n");
        exit(1);
    }
    $binSize = (int) filesize($combinedBin);
    $linked = true;
    fwrite(STDOUT, "bootstrap-gen0-chunk-link.php: linked {$combinedBin} ({$binSize} bytes)\n");
}

$receipt = [
    'version' => 1,
    'generated_at' => gmdate('c'),
    'out_dir' => $outDir,
    'plan' => $planPath !== '' ? $planPath : null,
    'combined_object' => $combinedO,
    'combined_bin' => $linked ? $combinedBin : null,
    'object_count' => count($objects),
    'chunk_ids' => array_map(static fn (array $r): string => $r['chunk_id'], $rows),
    'helper_slug_count' => count($slugs),
    'helper_slugs' => $slugs,
    'combined_size_bytes' => (int) filesize($combinedO),
    'bin_size_bytes' => $binSize,
    'executable_linked' => $linked,
    'lowering_source_fingerprint' => $wantFp,
    'note' => 'Gen-0 chunk ld -r combine (+ optional helper-slug executable link) (#36387).',
];
file_put_contents($receiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDOUT, "bootstrap-gen0-chunk-link.php: receipt {$receiptPath}\n");
exit(0);
