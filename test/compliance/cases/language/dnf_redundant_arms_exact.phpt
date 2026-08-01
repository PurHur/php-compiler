--TEST--
Language: exact duplicate DNF arms — compile fatal Type A&B is redundant with type A&B (#26606, zend_compile.c)
--FILE--
<?php
interface A {}
interface B {}
function f((A&B)|(A&B) $x) {
    echo "ran\n";
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
%AType A&B is redundant with type A&B%A
