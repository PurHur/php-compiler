--TEST--
Stdlib: ReflectionClass::newInstance() / newInstanceArgs() (#22086, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public function __construct(public int $x = 0) {}
}

$r = new ReflectionClass(C::class);
echo method_exists($r, 'newInstance') ? 'y' : 'n', "\n";
echo method_exists($r, 'newInstanceArgs') ? 'y' : 'n', "\n";
echo method_exists($r, 'newInstanceWithoutConstructor') ? 'y' : 'n', "\n";

$a = $r->newInstance(5);
$b = $r->newInstanceArgs([7]);
echo $a->x, '|', $b->x, "\n";

final class PrivateCtor {
    private function __construct() {}
}

try {
    (new ReflectionClass(PrivateCtor::class))->newInstance();
    echo "private_fail\n";
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}

abstract class Abs {}
try {
    (new ReflectionClass(Abs::class))->newInstance();
    echo "abs_fail\n";
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
y
y
y
5|7
Class PrivateCtor is not instantiable
Class Abs is not instantiable
