--TEST--
AOT: date(T/e) after date_default_timezone_set follows runtime zone (#33956)
--FILE--
<?php
var_export(date_default_timezone_set('Europe/Berlin'));
echo "\n";
echo date_default_timezone_get(), "\n";
echo date('T', 1721037600), "\n";
echo date('e', 1721037600), "\n";
?>
--EXPECT--
true
Europe/Berlin
CEST
Europe/Berlin
