<?php

declare(strict_types=1);

class C {
    private(set) int $x = 1;
}

$p = new ReflectionProperty(C::class, 'x');
var_dump(method_exists($p, 'getSettableType'));
var_dump(method_exists($p, 'getReadableType'));
if (method_exists($p, 'getSettableType')) {
    var_dump((string) $p->getReadableType(), (string) $p->getSettableType());
}
