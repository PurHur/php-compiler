--TEST--
stdlib timezone_open(null) — DEP+coerce on 8.4 forward profile (#21369, re-#18796, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
