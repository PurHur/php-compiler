<?php

enum E {
    case A;
}

$r = new ReflectionEnumUnitCase(E::class, 'A');
try {
    var_dump($r->getValue());
} catch (\Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

enum B: string {
    case A = 'x';
}

$rb = new ReflectionEnumUnitCase(B::class, 'A');
var_dump($rb->getValue());
