--TEST--
Language: runtime duplicate function declaration is uncatchable CompileError (#31109, Zend/zend_compile.c)
--FILE--
<?php
try {
    if (true) {
        function f() {}
        function f() {}
    }
} catch (Throwable $e) {
    echo "CAUGHT\n";
}
echo "REACHED\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Cannot redeclare f() (previously declared in %s:%d) in %s on line %d
