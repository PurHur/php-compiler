--TEST--
Language: static $a = $param compile-fatal on 8.2 profile (#22923, Zend/zend_compile.c)
--FILE--
<?php
function f($x) {
    static $a = $x;
    return $a;
}
echo f(1), ",", f(2), "\n";
--EXPECT_EXIT--
255
--EXPECTREGEX--
Constant expression contains invalid operations
