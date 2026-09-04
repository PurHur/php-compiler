<?php

declare(strict_types=1);

/**
 * Discarded defined / array_key_exists / key_exists must not change observable output (#36386).
 *
 * php-src: ext/standard/basic_functions.c (defined), ext/standard/array.c (array_key_exists)
 */

function work(string $c, string $k): string
{
    $a = ['k' => 1, 2 => 3];
    defined($c);
    array_key_exists($k, $a);
    key_exists(2, $a);

    $d = defined('PHP_VERSION') ? '1' : '0';
    $e = defined('NO_SUCH_CONST_36386') ? '1' : '0';
    $f = array_key_exists('k', $a) ? '1' : '0';
    $g = array_key_exists('missing', $a) ? '1' : '0';
    $h = key_exists(2, $a) ? '1' : '0';

    return $d.$e.$f.$g.$h;
}

echo work('PHP_VERSION', 'k'), "\n";
echo work('NO_SUCH_CONST_36386', 'missing'), "\n";
