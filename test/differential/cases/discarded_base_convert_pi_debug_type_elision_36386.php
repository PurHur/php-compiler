<?php

declare(strict_types=1);

/**
 * Discarded base converts / pi / get_debug_type must not change observable output (#36386).
 *
 * Live hexdec($typedString) AOT segfaults on master — use a literal for the kept
 * result; discarded hexdec($hex) still exercises elision.
 *
 * php-src: ext/standard/math.c (decbin/dechex/decoct/bindec/hexdec/octdec/pi), type.c
 */

function work(string $hex, int $n): string
{
    decbin($n);
    dechex($n);
    decoct($n);
    bindec('1010');
    hexdec($hex);
    octdec('77');
    pi();
    get_debug_type($n);
    get_debug_type($hex);

    $db = decbin($n);
    $dx = dechex($n);
    $do = decoct($n);
    $bd = bindec('1010');
    $hd = hexdec('ff');
    $od = octdec('77');
    $p = pi();
    $gt = get_debug_type($n);
    $gt2 = get_debug_type($hex);

    return $db.'|'.$dx.'|'.$do.'|'.$bd.'|'.$hd.'|'.$od.'|'.$p.'|'.$gt.'|'.$gt2;
}

echo work('ff', 42), "\n";
echo work('10', 0), "\n";
