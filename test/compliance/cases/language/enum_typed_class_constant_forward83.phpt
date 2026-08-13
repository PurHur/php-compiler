--TEST--
Language: enum typed user const E::FOO resolves at runtime (zend_enum.c, #6016)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
enum E: int {
    case A = 1;
    public const int FOO = 2;
}
echo E::FOO, "\n";
--EXPECT--
2
