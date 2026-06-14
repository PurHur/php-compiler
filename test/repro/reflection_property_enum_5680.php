<?php

declare(strict_types=1);

enum E: string
{
    case A = 'a';
}

$r = new ReflectionProperty(E::class, 'name');
var_export($r->getValue(E::A));
echo "\n";
$r2 = new ReflectionProperty(E::class, 'value');
var_export($r2->getValue(E::A));
echo "\n";
