--TEST--
Language: object|(A&B) DNF union — compile fatal Type (A&B)|object redundant (#26563, zend_compile.c)
--FILE--
<?php
interface A {}
interface B {}
function f(object|(A&B) $x) {
    echo "ran\n";
}
--EXPECT_EXIT--
255
--EXPECTF--
%AType (A&B)|object contains both object and a class type, which is redundant%A
