<?php
declare(strict_types=1);

enum E: int {
    #[\Deprecated(message: 'old case', since: '8.4')]
    case A = 1;
    case B = 2;
}

$rA = new ReflectionEnumUnitCase(E::class, 'A');
$rB = new ReflectionEnumUnitCase(E::class, 'B');
var_dump(method_exists($rA, 'isDeprecated'));
if (method_exists($rA, 'isDeprecated')) {
    var_dump($rA->isDeprecated());
    var_dump($rB->isDeprecated());
}
