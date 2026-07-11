--TEST--
stdlib json_validate() — function_exists profile gate (#16091, ext/json/php_json.c)
--FILE--
<?php
declare(strict_types=1);

echo 'default=', var_export(function_exists('json_validate'), true), "\n";
?>
--EXPECT--
default=false
