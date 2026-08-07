<?php

declare(strict_types=1);

// Repro #28565 — IEEE float phantoms absent on all profiles; fpow only on PROFILE≥8.4.

$phantoms = ['fadd', 'fsub', 'fmul', 'fmax', 'fmin', 'nextafter'];
foreach ($phantoms as $fn) {
    if (function_exists($fn)) {
        echo "fail: function_exists({$fn}) true\n";
        exit(1);
    }
}
if (!function_exists('fdiv') || !function_exists('fmod')) {
    echo "fail: fdiv/fmod missing\n";
    exit(1);
}

$profile = getenv('PHP_COMPILER_PROFILE');
$forward = is_string($profile) && '' !== trim($profile)
    && version_compare(trim($profile), '8.4', '>=');
if ($forward) {
    if (!function_exists('fpow')) {
        echo "fail: function_exists(fpow) false (expected on PROFILE≥8.4)\n";
        exit(1);
    }
    echo "ok: phantoms withheld; fpow/fdiv/fmod present\n";
} else {
    if (function_exists('fpow')) {
        echo "fail: fpow present on reference profile\n";
        exit(1);
    }
    echo "ok: phantoms withheld on reference profile\n";
}
