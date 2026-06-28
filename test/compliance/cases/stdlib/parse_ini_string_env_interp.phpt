--TEST--
stdlib parse_ini_string() ${ENV} interpolation in double-quoted values (#12928, ext/standard/ini.c)
--FILE--
<?php
declare(strict_types=1);

var_export(parse_ini_string('a="${x}"' . "\n" . 'b=2'));
echo "\n";
putenv('PHP_COMPILER_INI_ENV_TEST=hello');
var_export(parse_ini_string('a="${PHP_COMPILER_INI_ENV_TEST}"'));
echo "\n";
putenv('PHP_COMPILER_INI_ENV_TEST');
--EXPECT--
array (
  'a' => '',
  'b' => '2',
)
array (
  'a' => 'hello',
)
