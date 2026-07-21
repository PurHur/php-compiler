#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Content fingerprint for bootstrap compiled-driver reuse (#21855).
 *
 * Invalidates gen-0 AOT blobs when lib/, ext/, or patches/ lowering inputs change —
 * not only when the spine entry require list changes.
 *
 * Usage:
 *   php script/bootstrap-lowering-source-fingerprint.php
 *   php script/bootstrap-lowering-source-fingerprint.php --check STAMP_FILE
 */

$root = dirname(__DIR__);

/**
 * @return list<string> repo-relative paths
 */
function bootstrap_lowering_source_paths(string $root): array
{
    $paths = [];
    foreach (['lib', 'ext', 'patches'] as $dir) {
        $abs = $root.'/'.$dir;
        if (!is_dir($abs)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($root) + 1);
            $rel = str_replace('\\', '/', $rel);
            $paths[] = $rel;
        }
    }
    foreach ([
        'composer.lock',
        'script/apply-patches.sh',
        'bin/compile.php',
    ] as $rel) {
        if (is_readable($root.'/'.$rel)) {
            $paths[] = $rel;
        }
    }
    sort($paths, SORT_STRING);

    return $paths;
}

function bootstrap_lowering_source_fingerprint(string $root): string
{
    $ctx = hash_init('sha256');
    foreach (bootstrap_lowering_source_paths($root) as $rel) {
        $abs = $root.'/'.$rel;
        $hash = @hash_file('sha256', $abs);
        if (!is_string($hash) || '' === $hash) {
            continue;
        }
        hash_update($ctx, $rel."\0".$hash."\n");
    }

    return hash_final($ctx);
}

// Library-safe include (#21905 / a7858871d): CLI only when executed as main script.
if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $fp = bootstrap_lowering_source_fingerprint($root);

    if (in_array('--check', $argv ?? [], true)) {
        $idx = array_search('--check', $argv, true);
        $stampPath = $argv[$idx + 1] ?? '';
        if ('' === $stampPath || !is_readable($stampPath)) {
            fwrite(STDERR, "bootstrap-lowering-source-fingerprint: --check requires readable stamp path\n");
            exit(1);
        }
        $have = trim((string) file_get_contents($stampPath));
        if ($fp === $have) {
            fwrite(STDOUT, "bootstrap-lowering-source-fingerprint: OK ({$fp})\n");
            exit(0);
        }
        if ('1' === getenv('BOOTSTRAP_ALLOW_STALE_COMPILED_DRIVER')
            || '1' === getenv('BOOTSTRAP_ALLOW_STALE_SIDECAR')) {
            fwrite(STDOUT, "bootstrap-lowering-source-fingerprint: WAIVED stale stamp (have {$have}, want {$fp})\n");
            exit(0);
        }
        fwrite(STDERR, "bootstrap-lowering-source-fingerprint: FAILED — stamp {$have} ≠ lowering source {$fp}\n");
        exit(1);
    }

    fwrite(STDOUT, $fp."\n");
    exit(0);
}
