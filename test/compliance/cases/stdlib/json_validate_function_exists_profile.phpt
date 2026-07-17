--TEST--
stdlib json_validate() — function_exists on default 8.4.0-dev (#16091, #19951, ext/json/php_json.c)
--FILE--
<?php
declare(strict_types=1);

echo 'default=', var_export(function_exists('json_validate'), true), "\n";
?>
--EXPECT--
default=true
