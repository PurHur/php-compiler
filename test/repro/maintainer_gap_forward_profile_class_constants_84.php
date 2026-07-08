<?php

declare(strict_types=1);

if (!function_exists('class_constants')) {
    echo "fail: class_constants not registered on forward 8.4 profile\n";
    exit(1);
}

interface I17434
{
    public const X = 1;
}

$c = class_constants('I17434');
if ($c !== ['X' => 1]) {
    echo 'fail: unexpected result ';
    var_export($c);
    echo "\n";
    exit(1);
}

echo "ok\n";
