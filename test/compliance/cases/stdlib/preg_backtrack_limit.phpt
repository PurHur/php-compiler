--TEST--
Stdlib: preg_last_error() — pcre.backtrack_limit exhausted (#12289, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);
@ini_set('pcre.backtrack_limit', '1');
@preg_match('/(a+)+b/', str_repeat('a', 100) . 'b');
echo 'code=' . preg_last_error() . ' msg=' . preg_last_error_msg() . "\n";
@ini_restore('pcre.backtrack_limit');
?>
--EXPECT--
code=2 msg=Backtrack limit exhausted
