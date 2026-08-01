--TEST--
Language: duplicate intersection members — compile fatal Duplicate type is redundant (#26605, zend_compile.c)
--FILE--
<?php
interface A {}
interface B {}
function f(A&B&A $x) {}
echo "reached\n";
--EXPECT_EXIT--
255
--EXPECTF--
%ADuplicate type A is redundant%A
