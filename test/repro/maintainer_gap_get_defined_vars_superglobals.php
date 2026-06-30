<?php

declare(strict_types=1);

/**
 * Issue #10934 — get_defined_vars() must include superglobals and argv/argc.
 */

$a = 1;
$keys = array_keys(get_defined_vars());
sort($keys);
echo implode(',', $keys), PHP_EOL;
echo array_key_exists('_GET', get_defined_vars()) ? 'has_get' : 'no_get', PHP_EOL;
echo array_key_exists('argv', get_defined_vars()) ? 'has_argv' : 'no_argv', PHP_EOL;

function inner(): void
{
    $b = 2;
    $innerKeys = array_keys(get_defined_vars());
    sort($innerKeys);
    echo 'inner=', implode(',', $innerKeys), PHP_EOL;
}

inner();
