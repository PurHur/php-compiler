<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

$tests = [
    ['utf8_encode', static fn () => utf8_encode(E::A)],
    ['str_rot13', static fn () => str_rot13(E::A)],
    ['addcslashes', static fn () => addcslashes(E::A, 'a')],
    ['stripcslashes', static fn () => stripcslashes(E::A)],
];

foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo $name, ": uncaught\n";
    } catch (TypeError $e) {
        echo $name, ': ', $e->getMessage(), "\n";
    }
}
