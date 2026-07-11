<?php

enum E {
    case A;
}

$r = new ReflectionEnumUnitCase(E::class, 'A');
var_export($r->getValue());
echo "\n";
echo $r->getValue()->name, "\n";

enum B: string {
    case A = 'x';
}

$rb = new ReflectionEnumUnitCase(B::class, 'A');
var_dump($rb->getValue());
