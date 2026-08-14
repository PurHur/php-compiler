--TEST--
Language: static::CONST rejected in parameter default (#31145, Zend/zend_compile.c)
--FILE--
<?php
class C {
    const X = 1;
    function f($a = static::X) {}
}
echo "parsed\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  "static::" is not allowed in compile-time constants in %s on line %d
