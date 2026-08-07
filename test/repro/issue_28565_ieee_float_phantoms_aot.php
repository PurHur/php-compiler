<?php

declare(strict_types=1);

/**
 * #28565 AOT probe — IEEE float phantoms absent; fpow remains on PROFILE≥8.4.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/x test/repro/issue_28565_ieee_float_phantoms_aot.php && /tmp/x
 *
 * Avoid foreach+variable function_exists (thin AOT IR verify failure on ret i64/i1).
 */

if (function_exists('fadd') || function_exists('fsub') || function_exists('fmul')
    || function_exists('fmax') || function_exists('fmin') || function_exists('nextafter')) {
    echo "fail: phantom present\n";
    exit(1);
}
if (!function_exists('fpow')) {
    echo "fail: fpow missing\n";
    exit(1);
}
echo "ok\n";
