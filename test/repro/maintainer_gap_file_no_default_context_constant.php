<?php
declare(strict_types=1);

if (!\defined('FILE_NO_DEFAULT_CONTEXT')) {
    fwrite(STDERR, "FAIL: FILE_NO_DEFAULT_CONTEXT undefined\n");
    exit(1);
}

$actual = \constant('FILE_NO_DEFAULT_CONTEXT');
if (16 !== $actual) {
    fwrite(STDERR, "FAIL: FILE_NO_DEFAULT_CONTEXT = $actual, expected 16\n");
    exit(1);
}

$path = sys_get_temp_dir().'/file_no_default_ctx_'.getmypid().'.txt';
file_put_contents($path, 'probe');
$fh = fopen($path, 'r', false, null);
if (false === $fh) {
    fwrite(STDERR, "FAIL: fopen with default context failed\n");
    exit(1);
}
$data = fread($fh, 5);
fclose($fh);
@unlink($path);
if ('probe' !== $data) {
    fwrite(STDERR, "FAIL: fread returned ".var_export($data, true)."\n");
    exit(1);
}

echo "OK\n";
