--TEST--
Language: duplicate trait declaration — Zend "Cannot declare trait" compile fatal (#31110, zend_compile.c)
--INI--
display_errors=1
--FILE--
<?php
echo "before\n";
try {
    trait T {}
    trait T {}
    echo "after\n";
} catch (Throwable $e) {
    echo get_class($e), ":", $e->getMessage(), "\n";
}
echo "continued\n";
--EXPECT_EXIT--
255
--EXPECTF--
before

Fatal error: Cannot declare trait T, because the name is already in use in %s on line %d
