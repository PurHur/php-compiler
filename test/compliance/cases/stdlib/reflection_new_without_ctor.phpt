--TEST--
Stdlib: ReflectionClass::newInstanceWithoutConstructor() — bypass private ctor (#5443, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

final class NoCtor {
    private function __construct() {}
    public int $x = 5;
}

$r = new ReflectionClass(NoCtor::class);
$o = $r->newInstanceWithoutConstructor();
var_export($o instanceof NoCtor);
echo "\n";
var_export($o->x);
echo "\n";

class Plain {
    public int $y = 7;
}
$o2 = (new ReflectionClass(Plain::class))->newInstanceWithoutConstructor();
var_export($o2->y);
echo "\n";

abstract class Abs {}
try {
    (new ReflectionClass(Abs::class))->newInstanceWithoutConstructor();
    echo "abs_fail\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
5
7
Cannot instantiate abstract class Abs
