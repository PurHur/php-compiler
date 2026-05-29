--TEST--
AOT preg_last_error_msg() after invalid pattern (issue #3110)
--FILE--
<?php
preg_match('bad', 'x');
echo preg_last_error_msg(), "\n";
--EXPECT--
Unknown error
