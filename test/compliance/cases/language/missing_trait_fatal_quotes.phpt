--TEST--
Language: missing trait Fatal quotes + Fatal error framing (#30012, zend_compile.c)
--FILE--
<?php
class A {
    use MissingTrait;
}
echo "unreached\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Trait "MissingTrait" not found in %s on line %d
