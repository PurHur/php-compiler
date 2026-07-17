--TEST--
stdlib implode()/join()/token_get_all()/sscanf(null) TypeError on 8.4 (#19894)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
implode_token_sscanf_null_forward84.php
--EXPECT--
implode: implode(): Argument #1 ($separator) must be of type array|string, null given
join: join(): Argument #1 ($separator) must be of type array|string, null given
token_get_all: token_get_all(): Argument #1 ($source) must be of type string, null given
sscanf: sscanf(): Argument #1 ($string) must be of type string, null given
