--TEST--
stdlib timezone_open(null) — false not TypeError (#18743, ext/date/php_date.c)
--FILE--
<?php
$result = timezone_open(null);
var_export($result);
echo "\n";
?>
--EXPECTF--
PHP Warning:  timezone_open(): Unknown or bad timezone () in %s on line %d
false
