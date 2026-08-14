<?php

/**
 * Repro #30830 — zlib stream helpers excess argc → ArgumentCountError.
 * php-src: ext/zlib/zlib.c
 */
$src = sys_get_temp_dir().'/phpc_30830_src_'.getmypid().'.txt';
file_put_contents($src, "hello zlib stream argc\n");
$zp = gzopen($src, 'r');
$tmp = sys_get_temp_dir().'/phpc_30830_'.getmypid().'.gz';
$zw = gzopen($tmp, 'w');
if ($zp === false || $zw === false) {
    echo "OPEN_FAIL\n";
    exit(1);
}
$cases = [
    'gzclose' => static fn () => gzclose($zp, 1),
    'gzeof' => static fn () => gzeof($zp, 1),
    'gzgetc' => static fn () => gzgetc($zp, 1),
    'gzgets' => static fn () => gzgets($zp, 1024, 1),
    'gzpassthru' => static fn () => gzpassthru($zp, 1),
    'gzrewind' => static fn () => gzrewind($zp, 1),
    'gzseek' => static fn () => gzseek($zp, 0, SEEK_SET, 1),
    'gztell' => static fn () => gztell($zp, 1),
    'gzread' => static fn () => gzread($zp, 10, 1),
    'gzwrite' => static fn () => gzwrite($zw, 'a', 1, 1),
    'gzputs' => static fn () => gzputs($zw, 'a', 1, 1),
];
foreach ($cases as $name => $call) {
    try {
        $call();
        echo $name, ":NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
$chunk = gzread($zp, 4);
echo 'ok=', (is_string($chunk) && strlen($chunk) <= 4) ? '1' : '0', "\n";
@gzclose($zp);
@gzclose($zw);
@unlink($tmp);
@unlink($src);
