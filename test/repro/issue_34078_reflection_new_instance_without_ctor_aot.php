<?php
declare(strict_types=1);

/**
 * AOT: ReflectionClass::newInstanceWithoutConstructor (#34078).
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_newInstanceWithoutConstructor
 */

final class Foo
{
    public int $x = 1;

    public function __construct()
    {
        $this->x = 99;
        echo "ctor\n";
    }
}

$o = (new ReflectionClass(Foo::class))->newInstanceWithoutConstructor();
echo get_class($o), ':', $o->x, "\n";

class Plain
{
    public int $y = 7;
}
$p = (new ReflectionClass(Plain::class))->newInstanceWithoutConstructor();
echo 'Plain:', $p->y, "\n";

abstract class Abs {}
try {
    (new ReflectionClass(Abs::class))->newInstanceWithoutConstructor();
    echo "abs_fail\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

try {
    (new ReflectionClass(Foo::class))->newInstanceWithoutConstructor(1);
    echo "argc_fail\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
