<?php

declare(strict_types=1);

/**
 * Maintainer repro (#5890): parse_str/quoted_printable_encode enum case TypeError.
 *
 * php-src: ext/standard/basic_functions.c, ext/standard/quot_print.c — Z_PARAM_STR
 */

enum Es: string
{
    case A = 'x';
}

$out = [];
try {
    parse_str(Es::A, $out);
    echo "parse_str uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    quoted_printable_encode(Es::A);
    echo "quoted_printable_encode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    quoted_printable_decode(Es::A);
    echo "quoted_printable_decode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
