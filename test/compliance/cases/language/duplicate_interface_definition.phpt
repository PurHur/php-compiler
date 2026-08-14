--TEST--
Language: duplicate interface declaration — Zend "Cannot declare interface" compile fatal (#31110, zend_compile.c)
--INI--
display_errors=1
--FILE--
<?php
echo "before\n";
try {
    interface I {}
    interface I {}
    echo "after\n";
} catch (Throwable $e) {
    echo get_class($e), ":", $e->getMessage(), "\n";
}
echo "continued\n";
--EXPECT_EXIT--
255
--EXPECTF--
before

Fatal error: Cannot declare interface I, because the name is already in use in %s on line %d
