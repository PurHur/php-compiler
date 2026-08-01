--TEST--
Language: duplicate union members false|null|false — compile fatal Duplicate type false is redundant (#26556, zend_compile.c)
--FILE--
<?php
function f(false|null|false $x) {
    echo "ran\n";
}
f(null);
--EXPECT_EXIT--
255
--EXPECTF--
%ADuplicate type false is redundant%A
