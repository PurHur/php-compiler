#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint gate (issue #212 Phase B).
 *
 * Runs bin/compile.php -l on each path in docs/bootstrap-profile.json aot_lint_targets.
 * Requires LLVM 9 (same as @group aot tests). Exits 2 when LLVM is missing (skip in CI).
 *
 * Usage:
 *   php script/bootstrap-aot-lint.php
 *   php script/bootstrap-aot-lint.php --verbose
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require __DIR__.'/bootstrap-lib.php';

$verbose = in_array('--verbose', $argv, true);
$profileFile = $root.'/docs/bootstrap-profile.json';

if (!is_file($profileFile)) {
    fwrite(STDERR, "Missing {$profileFile}; run: php script/bootstrap-profile.php\n");
    exit(1);
}

/** @var array<string, mixed> $profile */
$profile = json_decode((string) file_get_contents($profileFile), true);
if (!is_array($profile) || !isset($profile['aot_lint_targets']) || !is_array($profile['aot_lint_targets'])) {
    fwrite(STDERR, "Invalid bootstrap profile: {$profileFile}\n");
    exit(1);
}

$llvmDir = bootstrapResolveLlvmDir($root);
if (null === $llvmDir) {
    fwrite(STDERR, "bootstrap-aot-lint: LLVM 9 not found (skip)\n");
    exit(2);
}

$compileBin = $root.'/bin/compile.php';
if (!is_file($compileBin)) {
    fwrite(STDERR, "Missing {$compileBin}\n");
    exit(1);
}

$env = bootstrapLlvmProcessEnv($llvmDir);
$phpBin = PHP_BINARY;
$failures = [];

/**
 * Read stdout/stderr concurrently to avoid pipe deadlocks when one fills up.
 *
 * @param array{0: resource, 1: resource, 2: resource} $pipes
 * @return array{0: string, 1: string} [stdout, stderr]
 */
function bootstrapProcReadAll(array $pipes): array
{
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $open = [1 => true, 2 => true];
    while ($open !== []) {
        $read = [];
        if (isset($open[1])) {
            $read[] = $pipes[1];
        }
        if (isset($open[2])) {
            $read[] = $pipes[2];
        }
        if ([] === $read) {
            break;
        }
        $write = null;
        $except = null;
        $n = stream_select($read, $write, $except, 2);
        if (false === $n) {
            break;
        }
        if (0 === $n) {
            continue;
        }
        foreach ($read as $r) {
            if ($r === $pipes[1]) {
                $chunk = fread($pipes[1], 1 << 20);
                if (false !== $chunk && '' !== $chunk) {
                    $out .= $chunk;
                }
                if (feof($pipes[1])) {
                    unset($open[1]);
                }
            } elseif ($r === $pipes[2]) {
                $chunk = fread($pipes[2], 1 << 20);
                if (false !== $chunk && '' !== $chunk) {
                    $err .= $chunk;
                }
                if (feof($pipes[2])) {
                    unset($open[2]);
                }
            }
        }
    }

    return [$out, $err];
}

foreach ($profile['aot_lint_targets'] as $rel) {
    if (!is_string($rel)) {
        continue;
    }
    $path = $root.'/'.$rel;
    if (!is_file($path)) {
        $failures[] = "{$rel}: file not found";
        continue;
    }
    $cmd = [$phpBin, $compileBin, '-l', $path];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptorSpec, $pipes, $root, $env);
    if (!is_resource($proc)) {
        $failures[] = "{$rel}: proc_open failed";
        continue;
    }
    fclose($pipes[0]);
    [$stdout, $stderr] = bootstrapProcReadAll($pipes);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if (0 !== $code) {
        $detail = trim(($stderr !== false ? $stderr : '')."\n".($stdout !== false ? $stdout : ''));
        $failures[] = "{$rel}: exit {$code}".('' !== $detail ? "\n".$detail : '');
        continue;
    }
    if ($verbose) {
        fwrite(STDOUT, "OK {$rel}\n");
    }
}

if ($failures !== []) {
    fwrite(STDERR, "bootstrap-aot-lint failed:\n".implode("\n\n", $failures)."\n");
    exit(1);
}

fwrite(STDOUT, 'bootstrap-aot-lint: '.count($profile['aot_lint_targets'])." target(s) OK\n");

if (in_array('--link', $argv, true) || '1' === getenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK')) {
    $linkScript = $root.'/script/bootstrap-aot-link.sh';
    if (!is_file($linkScript)) {
        fwrite(STDERR, "Missing {$linkScript}\n");
        exit(1);
    }
    passthru('bash '.escapeshellarg($linkScript), $linkCode);
    exit(is_int($linkCode) ? $linkCode : 1);
}

exit(0);
