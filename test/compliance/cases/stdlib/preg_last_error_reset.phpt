--TEST--
stdlib preg_last_error() resets after successful match (issue #4375)
--FILE--
<?php
preg_match('not-a-regex', 'x');
echo preg_last_error() === PREG_INTERNAL_ERROR ? 'err' : 'no', "\n";
preg_match('/x/', 'x');
echo preg_last_error() === PREG_NO_ERROR ? 'ok' : 'no', "\n";
echo preg_last_error_msg(), "\n";
--EXPECT--
err
ok
No error
