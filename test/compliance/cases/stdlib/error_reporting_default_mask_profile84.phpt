--TEST--
stdlib error_reporting() — Zend 8.4 startup mask includes E_DEPRECATED (#26083)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo error_reporting(), "\n";
$prev = error_reporting(0);
echo 'during=', error_reporting(), "\n";
error_reporting($prev);
echo 'after=', error_reporting(), "\n";
--EXPECT--
30719
during=0
after=30719
