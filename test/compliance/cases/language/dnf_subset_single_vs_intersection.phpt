--TEST--
Language: A|(A&B) — compile fatal intersection more restrictive than class (#26607, zend_compile.c)
--FILE--
<?php
interface A {}
interface B {}
function f(A|(A&B) $x) {
    echo "ran\n";
}
--EXPECT_EXIT--
255
--EXPECTF--
%AType A&B is redundant as it is more restrictive than type A%A
