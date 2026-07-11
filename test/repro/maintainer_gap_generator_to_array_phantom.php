<?php

declare(strict_types=1);

/**
 * Issue #18084 — generator_to_array() must not advertise on 8.2 reference profile.
 *
 * php-src: ext/standard/basic_functions.c — generator_to_array since PHP 8.4
 */
if (function_exists('generator_to_array')) {
    echo "FAIL: generator_to_array advertised on reference profile\n";
    exit(1);
}

echo "ok: generator_to_array withheld on reference profile\n";
