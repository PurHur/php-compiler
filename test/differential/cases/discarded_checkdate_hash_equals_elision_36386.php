<?php

declare(strict_types=1);

/**
 * Discarded checkdate / hash_equals must not change observable output (#36386).
 *
 * php-src: ext/standard/datetime.c (checkdate), ext/hash/hash.c (hash_equals)
 */

function work(int $m, int $d, int $y, string $k, string $u): string
{
    checkdate($m, $d, $y);
    hash_equals($k, $u);

    $ok = checkdate(2, 29, 2024) ? '1' : '0';
    $bad = checkdate(2, 30, 2024) ? '1' : '0';
    $eq = hash_equals('secret', 'secret') ? '1' : '0';
    $ne = hash_equals('secret', 'other') ? '1' : '0';

    return $ok.'|'.$bad.'|'.$eq.'|'.$ne;
}

echo work(2, 29, 2024, 'a', 'b'), "\n";
echo work(1, 1, 2000, 'x', 'x'), "\n";
