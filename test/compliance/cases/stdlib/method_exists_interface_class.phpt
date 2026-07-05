--TEST--
Stdlib: method_exists() accepts interface/abstract ::class — bool not TypeError (#9486, ext/standard/class.c)
--FILE--
<?php
interface I {
    public function m(): void;
}

abstract class A {
    abstract public function m(): void;
}

var_export(method_exists(I::class, 'm'));
echo "\n";
var_export(method_exists(A::class, 'm'));
echo "\n";

class_alias(I::class, 'IAlias');
var_export(method_exists('IAlias', 'm'));
echo "\n";
--EXPECT--
true
true
true
