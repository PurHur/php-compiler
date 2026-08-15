--TEST--
Language: function static FCC init compile-fatal on ≤8.2 (#31168, Zend/zend_compile.c)
--FILE--
<?php
function f() {
    static $x = strlen(...);
    echo "ok\n";
}
f();
--EXPECT_EXIT--
255
--EXPECTREGEX--
Constant expression contains invalid operations
