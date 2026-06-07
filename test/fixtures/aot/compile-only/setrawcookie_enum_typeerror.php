<?php
declare(strict_types=1);
// Compile-only (#7413): setrawcookie() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'v'; }
enum V: string { case B = 'x'; }
foreach ([
    [E::A, 'cookie', 'name'],
    ['n', V::B, 'value'],
] as [$name, $value, $label]) {
    try {
        setrawcookie($name, $value);
        echo "{$label} uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
