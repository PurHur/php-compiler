<?php

declare(strict_types=1);

if (!function_exists('bzopen')) {
    echo "fail: bzopen not registered\n";
    exit(1);
}

if (!function_exists('bzcompress')) {
    echo "fail: bzcompress not registered\n";
    exit(1);
}

$tmp = sys_get_temp_dir().'/bzopen_stream_'.getmypid().'.bz2';
@unlink($tmp);

$fp = bzopen($tmp, 'w');
if (!is_resource($fp)) {
    echo "fail: bzopen write returned false\n";
    exit(1);
}

if ('bzip2' !== get_resource_type($fp)) {
    echo 'fail: resource type=', get_resource_type($fp), "\n";
    exit(1);
}

$written = bzwrite($fp, 'hello bzip2 stream');
if (!is_int($written) || $written !== 18) {
    echo "fail: bzwrite returned ", var_export($written, true), "\n";
    exit(1);
}

if (!bzclose($fp)) {
    echo "fail: bzclose write\n";
    exit(1);
}

$fp2 = bzopen($tmp, 'r');
if (!is_resource($fp2)) {
    echo "fail: bzopen read returned false\n";
    exit(1);
}

$out = bzread($fp2, 1024);
if ('hello bzip2 stream' !== $out) {
    echo 'fail: read got ', var_export($out, true), "\n";
    exit(1);
}

if (!bzclose($fp2)) {
    echo "fail: bzclose read\n";
    exit(1);
}

@unlink($tmp);
echo "ok\n";
