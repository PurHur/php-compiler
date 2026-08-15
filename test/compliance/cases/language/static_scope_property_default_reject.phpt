--TEST--
Language: static::CONST rejected in property default (#31145, Zend/zend_compile.c)
--FILE--
<?php
class C {
    const X = 1;
    public $a = static::X;
}
echo "parsed\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  "static::" is not allowed in compile-time constants in %s on line %d
