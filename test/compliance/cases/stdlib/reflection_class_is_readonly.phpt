--TEST--
ReflectionClass::isReadOnly() — readonly class probe (#5221, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

readonly class R {}
class C {}

$rr = new ReflectionClass(R::class);
$rc = new ReflectionClass(C::class);

echo method_exists(ReflectionClass::class, 'isReadOnly') ? "method:yes\n" : "method:no\n";
echo 'R=', ($rr->isReadOnly() ?? null) === true ? 'readonly' : 'not', "\n";
echo 'C=', ($rc->isReadOnly() ?? null) === true ? 'readonly' : 'not', "\n";
--EXPECT--
method:yes
R=readonly
C=not
