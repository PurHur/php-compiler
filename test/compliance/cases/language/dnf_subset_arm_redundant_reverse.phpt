--TEST--
Language: DNF subset arm reverse order (A&B&C)|(A&B) — compile fatal more restrictive (#26607, zend_compile.c)
--FILE--
<?php
interface A {}
interface B {}
interface C {}
function f((A&B&C)|(A&B) $x) {
    echo "ran\n";
}
--EXPECT_EXIT--
255
--EXPECTF--
%AType A&B&C is redundant as it is more restrictive than type A&B%A
