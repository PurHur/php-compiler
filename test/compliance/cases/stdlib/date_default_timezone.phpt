--TEST--
stdlib date_default_timezone_get/set — default timezone state (#3292)
--FILE--
<?php
echo function_exists('date_default_timezone_get') ? '1' : '0';
echo function_exists('date_default_timezone_set') ? '1' : '0';
echo "\n";
date_default_timezone_set('UTC');
echo date_default_timezone_get(), "\n";
var_export(date_default_timezone_set('Europe/Berlin'));
echo "\n";
echo date_default_timezone_get(), "\n";
var_export(date_default_timezone_set('Invalid/Zone'));
echo "\n";
echo date_default_timezone_get(), "\n";
--EXPECTF--
PHP Notice:  date_default_timezone_set(): Timezone ID 'Invalid/Zone' is invalid
11
UTC
true
Europe/Berlin
false
Europe/Berlin
