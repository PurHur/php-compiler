--TEST--
Stdlib: trigger_error(E_USER_ERROR) aborts like Zend (#16747)
--FILE--
<?php
trigger_error('fatal test', E_USER_ERROR);
echo "after\n";
--EXPECTF--
PHP Fatal error:  fatal test in %s on line %d
--EXPECT_EXIT--
255
