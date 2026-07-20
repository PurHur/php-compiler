--TEST--
stdlib timezone_open(null) — false not TypeError (#18743, ext/date/php_date.c)
--FILE--
<?php
error_reporting(E_ALL);
$result = timezone_open(null);
var_export($result);
echo "\n";
?>
--EXPECTF--
PHP Deprecated:  timezone_open(): Passing null to parameter #1 ($timezone) of type string is deprecated in %s on line %d
PHP Warning:  timezone_open(): Unknown or bad timezone () in %s on line %d
false
