<?php

declare(strict_types=1);

/**
 * Issue #17301 — bzopen()/bzread()/bzwrite()/bzclose() on PHP_COMPILER_PROFILE=8.4.
 */

$required = ['bzopen', 'bzread', 'bzwrite', 'bzclose', 'bzcompress'];
$missing = array_values(array_filter($required, static fn (string $fn): bool => !function_exists($fn)));
if ([] !== $missing) {
    fwrite(STDERR, 'fail: missing '.implode(',', $missing)."\n");
    exit(1);
}

$tmp = sys_get_temp_dir().'/phpc_bzopen_'.getmypid().'.bz2';
@unlink($tmp);

$plain = 'hello bzip2 stream';
$fp = bzopen($tmp, 'w');
if (!is_resource($fp)) {
    fwrite(STDERR, "fail: bzopen write\n");
    exit(1);
}
$written = bzwrite($fp, $plain);
if ($written !== strlen($plain)) {
    fwrite(STDERR, "fail: bzwrite length {$written}\n");
    exit(1);
}
if (!bzclose($fp)) {
    fwrite(STDERR, "fail: bzclose write\n");
    exit(1);
}

$fp = bzopen($tmp, 'r');
if (!is_resource($fp)) {
    fwrite(STDERR, "fail: bzopen read\n");
    exit(1);
}
$read = bzread($fp, 4096);
if ($read !== $plain) {
    fwrite(STDERR, "fail: bzread got ".var_export($read, true)."\n");
    exit(1);
}
if (!bzclose($fp)) {
    fwrite(STDERR, "fail: bzclose read\n");
    exit(1);
}

@unlink($tmp);
echo "ok\n";
