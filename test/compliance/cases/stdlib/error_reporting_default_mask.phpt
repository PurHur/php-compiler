--TEST--
stdlib error_reporting() — Zend startup mask E_ALL & ~E_DEPRECATED & ~E_STRICT (#4842)
--FILE--
<?php
echo error_reporting(), "\n";
$prev = error_reporting(E_ALL);
echo 'during=', error_reporting(), "\n";
error_reporting($prev);
echo 'after=', error_reporting(), "\n";
--EXPECT--
22527
during=32767
after=22527
