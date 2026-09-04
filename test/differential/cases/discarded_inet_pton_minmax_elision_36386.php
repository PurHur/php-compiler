<?php

declare(strict_types=1);

/**
 * Discarded inet_pton / inet_ntop / min / max must not change observable
 * output (#36386).
 *
 * php-src: ext/standard/basic_functions.c (inet_pton/inet_ntop), array.c (min/max)
 */

function work(string $ip, int $a, int $b): string
{
    inet_pton($ip);
    min($a, $b);
    max($a, $b);

    $p = inet_pton('127.0.0.1');
    $n = inet_ntop($p);
    $mi = min(3, 7);
    $ma = max(3, 7);

    return bin2hex((string) $p).'|'.$n.'|'.$mi.'|'.$ma;
}

echo work('127.0.0.1', 3, 7), "\n";
echo work('0.0.0.0', 9, 1), "\n";
