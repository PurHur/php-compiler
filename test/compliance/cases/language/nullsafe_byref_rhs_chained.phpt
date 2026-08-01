--TEST--
Language: &$a?->x->y AssignRef RHS chain — compile fatal nullsafe chain (#26638, zend_compile.c)
--FILE--
<?php
$a = (object) ['x' => (object) ['y' => 1]];
$b = &$a?->x->y;
echo "survived\n";
--EXPECT_EXIT--
255
--EXPECTF--
%ACannot take reference of a nullsafe chain%A
