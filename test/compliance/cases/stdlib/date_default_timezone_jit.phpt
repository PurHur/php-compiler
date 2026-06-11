--TEST--
stdlib date_default_timezone_get/set — JIT lowering (#3292 phase 2)
--JIT--
--FILE--
<?php
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
UTC
true
Europe/Berlin
false
Europe/Berlin
