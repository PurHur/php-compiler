--TEST--
stdlib preg_last_error_msg() after invalid pattern (issue #3110)
--FILE--
<?php
preg_match('not-a-regex', 'x');
echo preg_last_error_msg(), "\n";
preg_match('/ok/', 'ok');
echo preg_last_error_msg(), "\n";
--EXPECT--
Internal error
No error
