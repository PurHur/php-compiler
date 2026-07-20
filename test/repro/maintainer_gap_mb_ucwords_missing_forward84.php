<?php
// Repro #21394 / #20799 — mb_ucwords() on PHP_COMPILER_PROFILE=8.4 forward profile
if (!function_exists('mb_ucfirst')) {
    echo "fail: mb_ucfirst not registered\n";
    exit(1);
}
if (!function_exists('mb_ucwords')) {
    echo "fail: undefined mb_ucwords\n";
    exit(1);
}
$result = mb_ucwords('hello world');
if ('Hello World' !== $result) {
    echo 'fail: got ', var_export($result, true), "\n";
    exit(1);
}
echo "mb_ucwords=yes\n";
echo $result, "\n";
