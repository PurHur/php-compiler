<?php

declare(strict_types=1);

/**
 * Maintainer repro (#6259): convert_uuencode()/convert_uudecode() enum case TypeError.
 *
 * php-src: ext/standard/uuencode.c — Z_PARAM_STR
 */

enum Es: string
{
    case A = 'x';
}

try {
    convert_uuencode(Es::A);
    echo "encode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    convert_uudecode(Es::A);
    echo "decode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
