--TEST--
Language: Closure::bindTo(null, Enum::class) preserves enum case return (#9630, zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; }

$c = function (): E {
    return E::A;
};

$b = $c->bindTo(null, E::class);
$r = $b();

echo get_debug_type($r), "\n";
var_export($r === E::A);
echo "\n";

$c2 = function (): E {
    return E::A;
};
$b2 = $c2->bindTo(E::A, E::class);
$r2 = $b2();
var_export($r2 === E::A);
echo "\n";
--EXPECT--
E
true
true
