<?php

declare(strict_types=1);

/**
 * Issue #18084 — generator_to_array() forward profile AOT smoke.
 */
function gen(): Generator
{
    yield 1;
    yield 2;
}

if (!function_exists('generator_to_array')) {
    echo "fail: generator_to_array not advertised on 8.4 profile\n";
    exit(1);
}

var_export(generator_to_array(gen()));
echo "\nok\n";
