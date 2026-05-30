--TEST--
Stdlib: class_parents() / get_class_vars() — JIT (issue #3159)
--FILE--
<?php
class BaseJit3159 {}
class MidJit3159 extends BaseJit3159 { public $x = 2; }
class LeafJit3159 extends MidJit3159 { public $y = 3; private $z = 4; }

$p = class_parents(LeafJit3159::class);
echo count($p), "\n";
echo $p[0], "\n";
echo $p[1], "\n";

$v = get_class_vars(MidJit3159::class);
echo count($v), "\n";
echo $v['x'], "\n";
echo isset($v['y']) ? 'has-y' : 'no-y';
echo "\n";
--EXPECT--
2
MidJit3159
BaseJit3159
1
2
no-y
