--TEST--
Language: static::CONST rejected in class constant initializer (#31145, Zend/zend_compile.c)
--FILE--
<?php
class C {
    const X = 1;
    const Y = static::X;
}
echo "parsed\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  "static::" is not allowed in compile-time constants in %s on line %d
