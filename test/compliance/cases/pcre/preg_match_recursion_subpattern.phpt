--TEST--
Stdlib: preg_match() (?R) recursion — PREG_JIT_STACKLIMIT_ERROR (#16176, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);
preg_match('/(?R)/', 'x');
echo 'code=' . preg_last_error() . ' msg=' . preg_last_error_msg() . "\n";
?>
--EXPECT--
code=6 msg=JIT stack limit exhausted
