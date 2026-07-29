--TEST--
stdlib str_repeat/str_shuffle/ucfirst/lcfirst/ucwords null — coerce on 8.4 forward profile JIT (#24598, reverts #24213; ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
case_repeat_null_forward84.php
--EXPECT--
str_repeat: uncaught ''
str_shuffle: uncaught ''
ucfirst: uncaught ''
lcfirst: uncaught ''
ucwords: uncaught ''
