<?php

declare(strict_types=1);

if (!function_exists('array_replace_key')) {
    echo "fail: array_replace_key not registered on forward 8.4 profile\n";
    exit(1);
}

$a = ['a' => 1, 'b' => 2];
$b = array_replace_key($a, ['a' => 2]);
if ($b !== ['a' => 2, 'b' => 2]) {
    echo 'fail: unexpected result ';
    var_export($b);
    echo "\n";
    exit(1);
}

echo "ok\n";
