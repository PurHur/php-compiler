--TEST--
stdlib ReflectionClass::getMethods() IS_STATIC filter (#4480, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

class A {
    public function pubA(): void {}
    public static function statA(): void {}
}
class B extends A {
    public function pubB(): void {}
}

$r = new ReflectionClass(B::class);
foreach ($r->getMethods(ReflectionMethod::IS_STATIC) as $m) {
    echo $m->getDeclaringClass()->getName(), '::', $m->getName(), "\n";
}
--EXPECT--
A::statA
