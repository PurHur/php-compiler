#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Line-oriented delta-debug reducer for differential fuzz failures (#36398).
 *
 * Keeps the PHP header + declare block, then removes body lines via hierarchical
 * ddmin + greedy single-line passes while the oracle (Zend vs backend mismatch /
 * crash) still fires.
 *
 * Usage:
 *   php script/fuzz/reduce.php --in fail.php --backend vm --out reduced.php
 */

require __DIR__.'/lib.php';

$opts = getopt('', ['in:', 'out:', 'backend:', 'timeout:', 'help']);
if (isset($opts['help']) || !isset($opts['in'])) {
    fwrite(STDERR, "Usage: php script/fuzz/reduce.php --in FILE [--backend vm|aot] [--out FILE] [--timeout SEC]\n");
    exit(isset($opts['help']) ? 0 : 2);
}

$in = (string) $opts['in'];
if (!is_readable($in)) {
    fwrite(STDERR, "fuzz/reduce: cannot read {$in}\n");
    exit(2);
}
$backend = isset($opts['backend']) ? (string) $opts['backend'] : 'vm';
if (!in_array($backend, ['vm', 'aot'], true)) {
    fwrite(STDERR, "fuzz/reduce: --backend must be vm|aot\n");
    exit(2);
}
$timeout = isset($opts['timeout']) ? (int) $opts['timeout'] : 30;
$out = isset($opts['out']) ? (string) $opts['out'] : null;
$root = fuzz_repo_root();
$phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';

$original = (string) file_get_contents($in);
$originalProbe = fuzz_oracle_probe($original, $backend, $root, $phpBin, $timeout);
if ($originalProbe === null || !$originalProbe['interesting']) {
    fwrite(STDERR, "fuzz/reduce: input does not reproduce a Zend mismatch/crash under {$backend}\n");
    exit(1);
}
$wantSig = $originalProbe['signature'];

$reduced = fuzz_reduce_source(
    $original,
    static function (string $src) use ($backend, $root, $phpBin, $timeout, $wantSig): bool {
        $probe = fuzz_oracle_probe($src, $backend, $root, $phpBin, $timeout);
        return $probe !== null
            && $probe['interesting']
            && $probe['signature'] === $wantSig;
    }
);
$lineCount = fuzz_count_nonempty_lines($reduced);
$rawLines = substr_count($reduced, "\n") + (str_ends_with($reduced, "\n") ? 0 : 1);
fwrite(
    STDERR,
    "fuzz/reduce: {$lineCount} nonempty / {$rawLines} raw lines (from "
    .fuzz_count_nonempty_lines($original)." nonempty)\n"
);

if ($out !== null) {
    file_put_contents($out, $reduced);
    fwrite(STDOUT, $out."\n");
} else {
    fwrite(STDOUT, $reduced);
}

function fuzz_oracle_interesting(string $src, string $backend, string $root, string $phpBin, int $timeout): bool
{
    $probe = fuzz_oracle_probe($src, $backend, $root, $phpBin, $timeout);

    return $probe !== null && $probe['interesting'];
}

/**
 * @return ?array{interesting: bool, signature: string, kind: string, zend_rc: int, got_rc: int}
 */
function fuzz_oracle_probe(string $src, string $backend, string $root, string $phpBin, int $timeout): ?array
{
    $tmp = tempnam(sys_get_temp_dir(), 'fuzzred');
    if ($tmp === false) {
        return null;
    }
    $php = $tmp.'.php';
    rename($tmp, $php);
    file_put_contents($php, $src);

    try {
        // Must be valid PHP for Zend.
        [$zendOut, $zendRc, $zendTimed] = fuzz_reduce_run($timeout, [$phpBin, '-l', $php]);
        if ($zendTimed || $zendRc !== 0) {
            return null;
        }
        [$zendOut, $zendRc, $zendTimed] = fuzz_reduce_run(
            $timeout,
            [$phpBin, '-d', 'error_reporting=-1', '-d', 'display_errors=1', $php]
        );
        if ($zendTimed) {
            return null;
        }

        $kind = 'vm_diff';
        if ($backend === 'vm') {
            [$gotOut, $gotRc, $gotTimed] = fuzz_reduce_run(
                $timeout,
                [$phpBin, '-d', 'error_reporting=1', '-d', 'display_errors=stderr', $root.'/bin/vm.php', $php]
            );
        } else {
            $bin = $php.'.bin';
            [$clog, $crc, $ctimed] = fuzz_reduce_run(
                max($timeout, 120),
                [$phpBin, $root.'/bin/compile.php', '-o', $bin, $php]
            );
            if ($ctimed || $crc !== 0 || !is_file($bin)) {
                $sig = fuzz_normalize_signature('aot_crash', $zendRc, $crc, $zendOut, $clog);

                return [
                    'interesting' => true,
                    'signature' => $sig,
                    'kind' => 'aot_crash',
                    'zend_rc' => $zendRc,
                    'got_rc' => $crc,
                ];
            }
            [$gotOut, $gotRc, $gotTimed] = fuzz_reduce_run($timeout, [$bin]);
            @unlink($bin);
            $kind = 'aot_diff';
        }

        if ($gotTimed) {
            $kind = $backend === 'vm' ? 'vm_timeout' : 'aot_timeout';
            $sig = fuzz_normalize_signature($kind, $zendRc, 124, $zendOut, $gotOut);

            return [
                'interesting' => true,
                'signature' => $sig,
                'kind' => $kind,
                'zend_rc' => $zendRc,
                'got_rc' => 124,
            ];
        }
        if ($gotRc >= 128 || in_array($gotRc, [134, 139], true)) {
            $kind = $backend === 'vm' ? 'vm_crash' : 'aot_crash';
            $sig = fuzz_normalize_signature($kind, $zendRc, $gotRc, $zendOut, $gotOut);

            return [
                'interesting' => true,
                'signature' => $sig,
                'kind' => $kind,
                'zend_rc' => $zendRc,
                'got_rc' => $gotRc,
            ];
        }

        $interesting = !($zendOut === $gotOut && $zendRc === $gotRc);
        $sig = fuzz_normalize_signature($kind, $zendRc, $gotRc, $zendOut, $gotOut);

        return [
            'interesting' => $interesting,
            'signature' => $sig,
            'kind' => $kind,
            'zend_rc' => $zendRc,
            'got_rc' => $gotRc,
        ];
    } finally {
        @unlink($php);
    }
}

/**
 * @param list<string> $argv
 * @return array{0:string,1:int,2:bool}
 */
function fuzz_reduce_run(int $timeoutSec, array $argv): array
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
    if ($text !== '') {
        $text .= "\n";
    }

    return [$text, $rc, $rc === 124];
}
