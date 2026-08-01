--TEST--
Language: object|Class union — compile fatal Type A|object contains both object and a class type (#26563, zend_compile.c)
--FILE--
<?php
class A {}
function f(object|A $x) {
    echo "ran\n";
}
f(new A);
--EXPECT_EXIT--
255
--EXPECTF--
%AType A|object contains both object and a class type, which is redundant%A
