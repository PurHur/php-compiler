--TEST--
Language: function static anonymous class initializer compile-rejects (#15873, Zend/zend_compile.c)
--FILE--
<?php
function f() {
    static $x = new class {};
    echo "ok\n";
}
f();
--EXPECT_EXIT--
255
