--TEST--
stdlib preg_last_error_msg() JIT after bad pattern (issue #3110)
--FILE--
<?php
preg_match('[', 'x');
echo preg_last_error_msg(), "\n";
--EXPECT--
Unknown error
