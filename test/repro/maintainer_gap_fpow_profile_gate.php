<?php

declare(strict_types=1);

$required = ['fpow', 'fmin', 'fmax', 'stream_supports', 'attribute_exists'];
foreach ($required as $fn) {
    if (!function_exists($fn)) {
        echo "fail: function_exists({$fn}) false under PHP_COMPILER_PROFILE=8.4\n";
        exit(1);
    }
}

if (!enum_exists('RoundingMode', false)) {
    echo "fail: enum_exists(RoundingMode) false under PHP_COMPILER_PROFILE=8.4\n";
    exit(1);
}

echo "ok\n";
