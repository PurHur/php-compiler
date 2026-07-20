--TEST--
Reflection: ReflectionClassConstant::isFinal() for interface final const (#21395, ext/reflection/php_reflection.c)
--FILE--
<?php
interface I {
    final const X = 1;
}

class C implements I {
}

class D {
    final const F = 10;
    const N = 20;
}

$rc = new ReflectionClassConstant(C::class, 'X');
$r = $rc->isFinal();
var_dump($r);

$rcDirect = new ReflectionClassConstant(I::class, 'X');
$r = $rcDirect->isFinal();
var_dump($r);

$rcFinal = new ReflectionClassConstant(D::class, 'F');
$r = $rcFinal->isFinal();
var_dump($r);

$rcNon = new ReflectionClassConstant(D::class, 'N');
$r = $rcNon->isFinal();
var_dump($r);
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
