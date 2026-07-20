--TEST--
JIT implode()/join()/token_get_all()/sscanf soft-null on 8.4 (#21210/#21209/#21503)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
implode_token_sscanf_null_forward84.php
--EXPECT--
implode: uncaught
join: uncaught
token_get_all: uncaught
sscanf: uncaught
