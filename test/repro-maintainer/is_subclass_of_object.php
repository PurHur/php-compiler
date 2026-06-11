<?php
class A {}
class B extends A {}

$o = new B();
var_export(is_subclass_of($o, A::class)); // Zend: true
echo "\n";
var_export(is_subclass_of($o, B::class)); // Zend: false — VM: true (bug)
echo "\n";
var_export(is_subclass_of(B::class, A::class)); // Zend: true
echo "\n";
