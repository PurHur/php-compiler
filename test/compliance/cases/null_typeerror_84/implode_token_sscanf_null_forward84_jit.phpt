--TEST--
JIT implode()/join() soft-null; token_get_all TypeError; sscanf soft-null on 8.4 (#21210/#21209)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
implode_token_sscanf_null_forward84.php
--EXPECT--
implode: uncaught
join: uncaught
token_get_all: token_get_all(): Argument #1 ($source) must be of type string, null given
sscanf: uncaught
