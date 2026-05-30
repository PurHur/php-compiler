--TEST--
Stdlib: class_parents() / get_class_vars() — class introspection (VM, #3159)
--FILE--
<?php
class Base {}
class Child extends Base { public $a = 1; private $b = 2; }

$parents = class_parents(Child::class);
echo count($parents), "\n";
echo $parents[0], "\n";

$vars = get_class_vars(Child::class);
echo count($vars), "\n";
echo $vars['a'], "\n";
echo isset($vars['b']) ? 'has-b' : 'no-b';
echo "\n";
--EXPECT--
1
Base
1
1
no-b
