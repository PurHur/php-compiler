<?php

declare(strict_types=1);

/**
 * Maintainer repro: forward-profile gated builtins on PHP_COMPILER_PROFILE=8.4 (#17319).
 */

$required = ['disktotalspace', 'getmygrgid', 'strxfrm', 'convert_cyr_string'];
$missing = [];
foreach ($required as $fn) {
    if (!function_exists($fn)) {
        $missing[] = $fn;
    }
}

if ([] !== $missing) {
    echo 'fail: missing on 8.4 profile: '.implode(', ', $missing)."\n";
    exit(1);
}

echo "ok\n";
