--TEST--
AOT class_parents() / get_class_vars() (issue #3159)
--FILE--
<?php
class BaseAot3159 {}
class ChildAot3159 extends BaseAot3159 { public $a = 7; private $b = 8; }

$p = class_parents(ChildAot3159::class);
echo count($p) === 1 ? '1' : '0';
echo $p[0] === 'BaseAot3159' ? '1' : '0';

$v = get_class_vars(ChildAot3159::class);
echo count($v) === 1 && $v['a'] === 7 ? '1' : '0';
echo isset($v['b']) ? '1' : '0';
--EXPECT--
1110
