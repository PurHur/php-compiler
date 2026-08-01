--TEST--
Language: commutative DNF arms — compile fatal Type B&A is redundant with type A&B (#26606, zend_compile.c)
--FILE--
<?php
interface A {}
interface B {}
function f((A&B)|(B&A) $x) {
    echo "ran\n";
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AType B&A is redundant with type A&B%A
