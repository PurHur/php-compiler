--TEST--
Stdlib: method_exists() with interface/abstract ::class after prior call (#9486)
--FILE--
<?php
interface I {
    public function m(): void;
}

var_dump(get_class_methods(I::class));
var_dump(method_exists(I::class, 'm'));

abstract class A {
    abstract public function m(): void;
}

var_dump(method_exists(A::class, 'm'));

class_alias(I::class, 'IAlias');
var_dump(method_exists('IAlias', 'm'));
--EXPECT--
array(1) {
  [0]=>
  string(1) "m"
}
bool(true)
bool(true)
bool(true)
