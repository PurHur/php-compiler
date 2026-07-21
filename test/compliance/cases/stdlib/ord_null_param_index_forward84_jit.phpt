--TEST--
stdlib ord(null) deprecation cites parameter #1 ($character) JIT (#21668, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
ord_null_param_index_forward84.php
--EXPECT--
ord(): Passing null to parameter #1 ($character) of type string is deprecated
0
chr(): Passing null to parameter #1 ($codepoint) of type int is deprecated
'' . "\0" . ''
