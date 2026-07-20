<?php
// Repro #21394 / #20799 — mb_ucwords() on PHP_COMPILER_PROFILE=8.4
if (!function_exists('mb_ucfirst')) {
    echo "fail: mb_ucfirst missing\n";
    exit(1);
}
if (!function_exists('mb_ucwords')) {
    echo "fail: undefined mb_ucwords\n";
    exit(1);
}
echo "mb_ucwords=yes\n";
$out = mb_ucwords('hello world');
if ('Hello World' !== $out) {
    echo 'fail: got ', var_export($out, true), "\n";
    exit(1);
}
echo $out, "\n";
