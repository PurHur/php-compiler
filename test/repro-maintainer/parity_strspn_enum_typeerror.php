<?php

declare(strict_types=1);

/** Maintainer repro — strspn()/strcspn() enum case operands must TypeError (php-src ext/standard/string.c). */

enum E: string
{
    case A = 'abc';
}

$tests = [
    static fn () => strspn(E::A, 'a'),
    static fn () => strspn('a', E::A),
    static fn () => strcspn(E::A, 'a'),
    static fn () => strcspn('a', E::A),
];

foreach ($tests as $i => $fn) {
    try {
        $fn();
        echo "test{$i}: uncaught\n";
    } catch (TypeError $e) {
        echo "test{$i}: TypeError\n";
    } catch (Throwable $e) {
        echo 'test' . $i . ': ' . $e::class . "\n";
    }
}
