--TEST--
Language: DNF subset arm (A&B)|(A&B&C) — compile fatal more restrictive (#26607, zend_compile.c)
--FILE--
<?php
interface A {}
interface B {}
interface C {}
function f((A&B)|(A&B&C) $x) {
    echo "ran\n";
}
--EXPECT_EXIT--
255
--EXPECTF--
%AType A&B&C is redundant as it is more restrictive than type A&B%A
