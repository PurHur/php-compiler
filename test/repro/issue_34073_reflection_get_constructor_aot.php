<?php

// AOT: ReflectionClass::getConstructor matches Zend (#34073).
class WithCtor {
    public function __construct() {}
}
class NoCtor {}
class ParentCtor {
    public function __construct() {}
}
class ChildNoCtor extends ParentCtor {}

$w = (new ReflectionClass(WithCtor::class))->getConstructor();
echo 'W=', $w ? $w->getName() : 'null', "\n";
$n = (new ReflectionClass(NoCtor::class))->getConstructor();
echo 'N=', var_export($n, true), "\n";
$c = (new ReflectionClass(ChildNoCtor::class))->getConstructor();
echo 'C=', $c ? $c->getDeclaringClass()->getName() : 'null', "\n";
echo 'M=', $c ? $c->getName() : 'null', "\n";
