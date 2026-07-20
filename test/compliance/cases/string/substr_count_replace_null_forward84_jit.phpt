--TEST--
JIT: substr_count/substr_replace(null) soft-null on 8.4 forward profile (#21196)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
substr_count_replace_null_forward84.php
--EXPECT--
substr_count: OK=1 depr=1
substr_replace: OK=1 depr=1
