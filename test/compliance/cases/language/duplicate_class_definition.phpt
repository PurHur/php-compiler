--TEST--
Language: duplicate class declaration — Zend "Cannot declare class" compile fatal (#31110, zend_compile.c)
--INI--
display_errors=1
--FILE--
<?php
echo "before\n";
try {
    class C {}
    class C {}
    echo "after\n";
} catch (Throwable $e) {
    echo get_class($e), ":", $e->getMessage(), "\n";
}
echo "continued\n";
--EXPECT_EXIT--
255
--EXPECTF--
before

Fatal error: Cannot declare class C, because the name is already in use in %s on line %d
