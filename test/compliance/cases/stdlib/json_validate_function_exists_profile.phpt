--TEST--
stdlib json_validate() — withheld on default 8.4.0-dev reference (#16091, #22544, ext/json/php_json.c)
--FILE--
<?php
declare(strict_types=1);

echo 'default=', var_export(function_exists('json_validate'), true), "\n";
?>
--EXPECT--
default=false
