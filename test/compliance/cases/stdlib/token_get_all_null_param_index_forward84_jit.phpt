--TEST--
stdlib token_get_all(null) deprecation cites parameter #1 ($code) JIT (#21781, ext/tokenizer/tokenizer.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
token_get_all_null_param_index_forward84.php
--EXPECT--
token_get_all(): Passing null to parameter #1 ($code) of type string is deprecated
count=0
