<?php

declare(strict_types=1);

/**
 * Maintainer repro (#6008): stream_get_contents() enum case $length operand TypeError.
 *
 * php-src: ext/standard/streamsfuncs.c — Z_PARAM_LONG_OR_NULL
 */

enum E: string
{
    case A = 'x';
}

$f = tmpfile();
try {
    stream_get_contents($f, E::A);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
fclose($f);
