<?php

declare(strict_types=1);

$required = ['fpow', 'fmin', 'fmax', 'nextafter'];
foreach ($required as $fn) {
    if (!function_exists($fn)) {
        echo "fail: function_exists({$fn}) false under PHP_COMPILER_PROFILE=8.4\n";
        exit(1);
    }
}

echo "ok: fpow advertised\n";
