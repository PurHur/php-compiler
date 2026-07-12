<?php
declare(strict_types=1);

class C {
    public int $x {
        get => 1;
    }
}

$r = new ReflectionProperty(C::class, 'x');
var_dump($r->getValue(new C()));
