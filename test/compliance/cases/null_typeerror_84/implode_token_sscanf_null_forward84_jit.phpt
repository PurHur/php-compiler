--TEST--
stdlib implode()/join()/token_get_all()/sscanf(null) TypeError on 8.4 JIT (#19894/#21209 sscanf soft-null)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
implode_token_sscanf_null_forward84.php
--EXPECT--
implode: implode(): Argument #1 ($separator) must be of type array|string, null given
join: join(): Argument #1 ($separator) must be of type array|string, null given
token_get_all: token_get_all(): Argument #1 ($source) must be of type string, null given
sscanf: uncaught
