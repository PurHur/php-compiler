--TEST--
stdlib parse_ini_string() — flat and section INI parsing (#3263, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(function_exists('parse_ini_string'));
echo "\n";
var_export(parse_ini_string("a=1\nb=2"));
echo "\n";
$ini = <<<INI
[app]
name = "My App"
debug = on
port = 8080
INI;
var_export(parse_ini_string($ini, true));
echo "\n";
var_export(parse_ini_string(""));
echo "\n";
--EXPECT--
true
array (
  'a' => '1',
  'b' => '2',
)
array (
  'app' => array (
    'name' => 'My App',
    'debug' => '1',
    'port' => '8080',
  ),
)
array (
)
