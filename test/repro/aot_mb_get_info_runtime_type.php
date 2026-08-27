<?php

declare(strict_types=1);

/**
 * Probe: mb_get_info() with runtime type arg under AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_get_info)
 */
function pick(string $s): string
{
    return $s;
}

$t = pick('internal_encoding');
echo 'all=', json_encode(mb_get_info()), "\n";
echo 'lit=', json_encode(mb_get_info('internal_encoding')), "\n";
echo 'rt=', json_encode(mb_get_info($t)), "\n";
echo 'bad=', var_export(mb_get_info('nope'), true), "\n";
