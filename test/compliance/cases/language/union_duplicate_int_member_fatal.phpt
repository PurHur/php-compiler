--TEST--
Language: duplicate union members int|string|int — compile fatal Duplicate type int is redundant (#26556, zend_compile.c)
--FILE--
<?php
function f(int|string|int $x) {
    echo "ran\n";
}
f(1);
--EXPECT_EXIT--
255
--EXPECTF--
%ADuplicate type int is redundant%A
