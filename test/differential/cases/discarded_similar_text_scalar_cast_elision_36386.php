<?php

declare(strict_types=1);

/**
 * Discarded similar_text / scalar casts must not change observable output (#36386).
 *
 * php-src: ext/standard/string.c (similar_text), type.c / basic_functions.c
 */

function work(string $a, string $b, int $n, float $f, bool $t): string
{
    similar_text($a, $b);
    intval($n);
    intval($a, 10);
    floatval($f);
    doubleval($n);
    boolval($t);
    strval($a);
    strval($n);

    $sim = similar_text($a, $b);
    $i = intval($b, 10);
    $fv = floatval($n);
    $bv = boolval($a) ? '1' : '0';
    $sv = strval($f);

    return $sim.'|'.$i.'|'.$fv.'|'.$bv.'|'.$sv;
}

echo work('hello', 'hallo', 42, 1.5, true), "\n";
echo work('abc', 'xyz', 0, 0.0, false), "\n";
