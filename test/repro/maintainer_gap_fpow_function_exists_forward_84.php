<?php

declare(strict_types=1);

$required = ['fpow', 'fdiv', 'fmod'];
foreach ($required as $fn) {
    if (!function_exists($fn)) {
        echo "fail: function_exists({$fn}) false under PHP_COMPILER_PROFILE=8.4\n";
        exit(1);
    }
}
foreach (['fmin', 'fmax', 'fadd', 'fsub', 'fmul', 'nextafter'] as $fn) {
    if (function_exists($fn)) {
        echo "fail: phantom function_exists({$fn}) true (#28565)\n";
        exit(1);
    }
}

echo "ok: fpow advertised; IEEE phantoms withheld\n";
