--TEST--
Stdlib: preg_last_error() after pattern compile failure — PREG_INTERNAL_ERROR (#12288)
--FILE--
<?php
declare(strict_types=1);
@preg_match('/(/', 'x');
echo 'code=' . preg_last_error() . ' msg=' . preg_last_error_msg() . "\n";
?>
--EXPECT--
code=1 msg=Internal error
