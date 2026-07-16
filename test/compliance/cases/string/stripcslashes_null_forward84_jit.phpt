--TEST--
stdlib stripcslashes(null) TypeError on 8.4 forward profile JIT (#19432, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
stripcslashes_null_forward84.php
--EXPECT--
stripcslashes: stripcslashes(): Argument #1 ($string) must be of type string, null given
