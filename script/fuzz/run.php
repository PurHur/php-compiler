#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Differential fuzz runner: generate N programs, compare Zend vs VM and/or AOT (#36398).
 *
 * Usage (inside Docker / via docker-exec):
 *   php script/fuzz/run.php --count 50 --seed-base 1 --backend vm
 *   php script/fuzz/run.php --count 20 --seed-base 100 --backend both --keep-failures build/fuzz-fail
 *
 * Exit codes:
 *   0 — all programs matched
 *   1 — at least one mismatch/crash/timeout
 *   2 — usage / environment error
 */

require __DIR__.'/lib.php';

$opts = getopt('', [
    'count:',
    'seed-base:',
    'backend:',
    'outdir:',
    'keep-failures:',
    'timeout:',
    'compile-timeout:',
    'quiet',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, <<<TXT
Usage: php script/fuzz/run.php --count N [--seed-base S] [--backend vm|aot|both]
                               [--outdir DIR] [--keep-failures DIR]
                               [--timeout SEC] [--compile-timeout SEC] [--quiet]

TXT);
    exit(0);
}

$count = isset($opts['count']) ? (int) $opts['count'] : 0;
if ($count < 1) {
    fwrite(STDERR, "fuzz/run: --count N (>=1) is required\n");
    exit(2);
}

$seedBase = isset($opts['seed-base']) ? (int) $opts['seed-base'] : 1;
$backendOpt = isset($opts['backend']) ? (string) $opts['backend'] : 'vm';
if (!in_array($backendOpt, ['vm', 'aot', 'both'], true)) {
    fwrite(STDERR, "fuzz/run: --backend must be vm|aot|both\n");
    exit(2);
}
$backends = $backendOpt === 'both' ? ['vm', 'aot'] : [$backendOpt];
$timeout = isset($opts['timeout']) ? (int) $opts['timeout'] : 30;
$compileTimeout = isset($opts['compile-timeout']) ? (int) $opts['compile-timeout'] : 180;
$quiet = isset($opts['quiet']);
$root = fuzz_repo_root();

$outdir = isset($opts['outdir'])
    ? (string) $opts['outdir']
    : $root.'/build/fuzz-run-'.getmypid();
$keepFailures = isset($opts['keep-failures']) ? (string) $opts['keep-failures'] : null;

if (!is_dir($outdir) && !mkdir($outdir, 0777, true) && !is_dir($outdir)) {
    fwrite(STDERR, "fuzz/run: cannot create outdir {$outdir}\n");
    exit(2);
}
if ($keepFailures !== null && !is_dir($keepFailures) && !mkdir($keepFailures, 0777, true) && !is_dir($keepFailures)) {
    fwrite(STDERR, "fuzz/run: cannot create keep-failures {$keepFailures}\n");
    exit(2);
}

require_once $root.'/script/fuzz/generate.php';

$phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$seenSig = [];
$fail = 0;
$ok = 0;
$crash = 0;
$timeoutN = 0;
$deduped = 0;

for ($i = 0; $i < $count; ++$i) {
    $seed = $seedBase + $i;
    $srcPath = sprintf('%s/seed_%d.php', $outdir, $seed);
    $src = fuzz_generate_program($seed, 'auto');
    file_put_contents($srcPath, $src);

    [$zendOut, $zendRc, $zendTimed] = fuzz_run_cmd(
        $timeout,
        [$phpBin, '-d', 'error_reporting=-1', '-d', 'display_errors=1', $srcPath]
    );
    if ($zendTimed) {
        ++$timeoutN;
        ++$fail;
        fuzz_record_failure($keepFailures, $seed, 'zend_timeout', $src, $zendOut, $zendRc, '', -1, $seenSig, $deduped, $quiet);
        continue;
    }

    foreach ($backends as $backend) {
        if ($backend === 'vm') {
            [$gotOut, $gotRc, $gotTimed] = fuzz_run_cmd(
                $timeout,
                [$phpBin, '-d', 'error_reporting=1', '-d', 'display_errors=stderr',
                    $root.'/bin/vm.php', $srcPath]
            );
            $kind = 'vm';
        } else {
            $bin = sprintf('%s/seed_%d.bin', $outdir, $seed);
            [$clog, $crc, $ctimed] = fuzz_run_cmd(
                $compileTimeout,
                [$phpBin, $root.'/bin/compile.php', '-o', $bin, $srcPath]
            );
            if ($ctimed || $crc !== 0 || !is_file($bin)) {
                ++$crash;
                ++$fail;
                fuzz_record_failure(
                    $keepFailures,
                    $seed,
                    'aot_compile',
                    $src,
                    $zendOut,
                    $zendRc,
                    $clog,
                    $crc,
                    $seenSig,
                    $deduped,
                    $quiet
                );
                continue;
            }
            [$gotOut, $gotRc, $gotTimed] = fuzz_run_cmd($timeout, [$bin]);
            $kind = 'aot';
        }

        if ($gotTimed) {
            ++$timeoutN;
            ++$fail;
            fuzz_record_failure($keepFailures, $seed, $kind.'_timeout', $src, $zendOut, $zendRc, $gotOut, $gotRc, $seenSig, $deduped, $quiet);
            continue;
        }

        // Crash-class: signal-style exits (128+N) or 139/134 commonly seen for SEGV/ABRT.
        if ($gotRc >= 128 || in_array($gotRc, [134, 139], true)) {
            ++$crash;
            ++$fail;
            fuzz_record_failure($keepFailures, $seed, $kind.'_crash', $src, $zendOut, $zendRc, $gotOut, $gotRc, $seenSig, $deduped, $quiet);
            continue;
        }

        if ($zendOut === $gotOut && $zendRc === $gotRc) {
            ++$ok;
            if (!$quiet) {
                printf("ok      seed=%-6d %-3s\n", $seed, $kind);
            }
            continue;
        }

        ++$fail;
        fuzz_record_failure($keepFailures, $seed, $kind.'_diff', $src, $zendOut, $zendRc, $gotOut, $gotRc, $seenSig, $deduped, $quiet);
    }
}

printf(
    "\nfuzz/run: ok=%d fail=%d crash=%d timeout=%d unique_sigs=%d (count=%d backends=%s)\n",
    $ok,
    $fail,
    $crash,
    $timeoutN,
    count($seenSig),
    $count,
    implode(',', $backends)
);

exit($fail > 0 ? 1 : 0);

/**
 * @param list<string> $argv
 * @return array{0:string,1:int,2:bool} output, rc, timed_out
 */
function fuzz_run_cmd(int $timeoutSec, array $argv): array
{
    $cmd = 'timeout '.escapeshellarg((string) $timeoutSec);
    foreach ($argv as $a) {
        $cmd .= ' '.escapeshellarg($a);
    }
    $cmd .= ' 2>&1';
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    $text = implode("\n", $out);
    if ($out !== [] || $text !== '') {
        $text .= "\n";
    }
    // GNU timeout exits 124 on timeout.
    $timed = ($rc === 124);

    return [$text, $rc, $timed];
}

function fuzz_record_failure(
    ?string $keepDir,
    int $seed,
    string $kind,
    string $src,
    string $zendOut,
    int $zendRc,
    string $gotOut,
    int $gotRc,
    array &$seenSig,
    int &$deduped,
    bool $quiet
): void {
    $sig = fuzz_normalize_signature($kind, $zendRc, $gotRc, $zendOut, $gotOut);
    $isNew = !isset($seenSig[$sig]);
    if ($isNew) {
        $seenSig[$sig] = $seed;
    } else {
        ++$deduped;
    }

    if (!$quiet) {
        printf(
            "%-7s seed=%-6d sig=%s%s\n",
            strtoupper(str_replace('_', '-', $kind)),
            $seed,
            substr($sig, 0, 12),
            $isNew ? '' : ' (dup)'
        );
    }

    if ($keepDir === null || !$isNew) {
        return;
    }

    $base = sprintf('%s/%s_seed%d', $keepDir, $kind, $seed);
    file_put_contents($base.'.php', $src);
    file_put_contents($base.'.json', json_encode([
        'seed' => $seed,
        'kind' => $kind,
        'signature' => $sig,
        'zend_rc' => $zendRc,
        'got_rc' => $gotRc,
        'zend_out' => $zendOut,
        'got_out' => $gotOut,
    ], JSON_PRETTY_PRINT)."\n");
}
