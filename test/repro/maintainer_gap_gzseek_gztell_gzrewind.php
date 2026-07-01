<?php

declare(strict_types=1);

$missing = [];
foreach (['gzseek', 'gztell', 'gzrewind'] as $fn) {
    if (!\function_exists($fn)) {
        $missing[] = $fn;
    }
}
if ([] !== $missing) {
    echo 'FAIL: missing zlib stream seek API: ', implode(', ', $missing), "\n";
    exit(1);
}

$path = \sys_get_temp_dir().'/phpc_gzseek_test_'.\getmypid().'.gz';
$w = \gzopen($path, 'w9');
if (false === $w) {
    echo "FAIL: gzopen write\n";
    exit(1);
}
\gzwrite($w, 'hello world');
\gzclose($w);

$r = \gzopen($path, 'r');
if (false === $r) {
    echo "FAIL: gzopen read\n";
    @\unlink($path);
    exit(1);
}

if (0 !== \gzseek($r, 6)) {
    echo "FAIL: gzseek to 6\n";
    \gzclose($r);
    @\unlink($path);
    exit(1);
}
if (6 !== \gztell($r)) {
    echo "FAIL: gztell expected 6 got ", \var_export(\gztell($r), true), "\n";
    \gzclose($r);
    @\unlink($path);
    exit(1);
}
$tail = \gzread($r, 20);
if ('world' !== $tail) {
    echo "FAIL: gzread after seek expected world got ", \var_export($tail, true), "\n";
    \gzclose($r);
    @\unlink($path);
    exit(1);
}

if (!\gzrewind($r)) {
    echo "FAIL: gzrewind\n";
    \gzclose($r);
    @\unlink($path);
    exit(1);
}
if (0 !== \gztell($r)) {
    echo "FAIL: gztell after rewind\n";
    \gzclose($r);
    @\unlink($path);
    exit(1);
}
$all = \gzread($r, 20);
if ('hello world' !== $all) {
    echo "FAIL: gzread after rewind\n";
    \gzclose($r);
    @\unlink($path);
    exit(1);
}

\gzclose($r);
@\unlink($path);
echo "OK\n";
