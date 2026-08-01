--TEST--
Language: &$a?->x AssignRef RHS — compile fatal nullsafe chain (#26638, zend_compile.c)
--FILE--
<?php
$a = null;
$b = &$a?->x;
echo "survived\n";
--EXPECT_EXIT--
255
--EXPECTF--
%ACannot take reference of a nullsafe chain%A
