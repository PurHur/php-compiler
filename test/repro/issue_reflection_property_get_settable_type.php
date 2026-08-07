<?php

declare(strict_types=1);

class C {
    public string $x = 'a';
}

$r = new ReflectionProperty(C::class, 'x');
var_export($r->getSettableType());
echo "\n";
var_export($r->getType());
echo "\n";
