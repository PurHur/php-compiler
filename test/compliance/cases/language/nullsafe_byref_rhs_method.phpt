--TEST--
Language: &$obj?->m() AssignRef RHS — compile fatal nullsafe chain (#26638, zend_compile.c)
--FILE--
<?php
$c = null;
$b = &$c?->m();
echo "survived\n";
--EXPECT_EXIT--
255
--EXPECTF--
%ACannot take reference of a nullsafe chain%A
