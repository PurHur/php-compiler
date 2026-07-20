--TEST--
JIT: substr/strpos/strstr/explode/str_replace null soft-null on 8.4 (#21189)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
substr_search_soft_null_forward84.php
--EXPECT--
substr: OK=1 depr=1
strpos: OK=1 depr=1
strstr: OK=1 depr=1
explode: OK=1 depr=1
str_replace: OK=1 depr=1
