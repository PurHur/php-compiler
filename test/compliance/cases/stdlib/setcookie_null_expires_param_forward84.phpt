--TEST--
stdlib setcookie(null $expires) deprecation cites parameter #3 ($expires_or_options) (#21735, ext/standard/head.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
setcookie_null_expires_param_forward84.php
--EXPECT--
setcookie(): Passing null to parameter #3 ($expires_or_options) of type array|int is deprecated
