--TEST--
Language: final plain property rejected under explicit PROFILE=8.2 (#25379, re-#24895/#23403, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
class P {
    final public string $x = 'z';
}
echo "parsed\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot declare property P::$x final, the final modifier is allowed only for methods, classes, and class constants in %s on line %d
