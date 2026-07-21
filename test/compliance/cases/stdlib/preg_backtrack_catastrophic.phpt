--TEST--
Stdlib: preg_last_error() — catastrophic backtrack at default limit (#21958, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);
@preg_match('/(?:\D+|<\d+>)*[!?]/', 'foobar foobar foobar');
echo 'code=' . preg_last_error() . ' msg=' . preg_last_error_msg() . "\n";
var_export(preg_last_error() === PREG_BACKTRACK_LIMIT_ERROR);
echo "\n";
?>
--EXPECT--
code=2 msg=Backtrack limit exhausted
true
