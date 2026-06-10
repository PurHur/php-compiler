--TEST--
stdlib parse_ini_file() — reads INI fixture (#3263, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(function_exists('parse_ini_file'));
echo "\n";
$path = tempnam(sys_get_temp_dir(), 'phpc-ini-');
file_put_contents($path, "[app]\nname = \"My App\"\ndebug = on\nport = 8080\n");
var_export(parse_ini_file($path, true));
echo "\n";
@unlink($path);
var_export(@parse_ini_file('/no/such/file.ini'));
echo "\n";
--EXPECT--
true
array (
  'app' => array (
    'name' => 'My App',
    'debug' => '1',
    'port' => '8080',
  ),
)
false
