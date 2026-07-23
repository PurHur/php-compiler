--TEST--
stdlib json_validate() — function_exists on PHP_COMPILER_PROFILE=8.3 (#16091, #22544, ext/json/php_json.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
declare(strict_types=1);

echo 'forward=', var_export(function_exists('json_validate'), true), "\n";
?>
--EXPECT--
forward=true
