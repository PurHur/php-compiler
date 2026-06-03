--TEST--
AOT class_parents() / get_class_vars() (issue #3159, #5271 inherited defaults)
--FILE--
<?php
class BaseAot3159 { public $inherited = 11; }
class ChildAot3159 extends BaseAot3159 { public $a = 7; private $b = 8; }

$p = class_parents(ChildAot3159::class);
echo count($p) === 1 ? '1' : '0';
echo ($p['BaseAot3159'] ?? '') === 'BaseAot3159' ? '1' : '0';

$v = get_class_vars(ChildAot3159::class);
echo count($v) === 2 && $v['a'] === 7 && $v['inherited'] === 11 ? '1' : '0';
echo isset($v['b']) ? '1' : '0';
--EXPECT--
1110
