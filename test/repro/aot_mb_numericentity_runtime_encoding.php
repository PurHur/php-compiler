<?php

declare(strict_types=1);

/**
 * #35210 / #35254 — mb_encode/decode_numericentity() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_encode_numericentity)
 */
function enc(string $e): string
{
    return $e;
}

$map = [0, 0xffff, 0, 0xffff];
$s = substr('xA', 1);
$utf8 = enc('UTF-8');
$ascii = enc('ASCII');
echo 'utf8=', mb_encode_numericentity($s, $map, $utf8), "\n";
echo 'ascii=', mb_encode_numericentity($s, $map, $ascii), "\n";
$lit = 'UTF-8';
echo 'literal=', mb_encode_numericentity($s, $map, $lit), "\n";
echo 'hex=', mb_encode_numericentity($s, $map, $utf8, true), "\n";
$ent = substr('x&#65;', 1);
echo 'dec=', mb_decode_numericentity($ent, $map, $utf8), "\n";
try {
    echo mb_encode_numericentity($s, $map, enc('nope'));
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}
