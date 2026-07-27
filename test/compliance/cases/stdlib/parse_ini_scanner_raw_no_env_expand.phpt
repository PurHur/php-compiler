--TEST--
stdlib parse_ini_string() INI_SCANNER_RAW keeps literal ${ENV} in double quotes (#23563, ext/standard/ini.c)
--FILE--
<?php
declare(strict_types=1);

putenv('PHPC_INI_TEST=fromenv');
var_export(parse_ini_string('a="${PHPC_INI_TEST}"', false, INI_SCANNER_RAW));
echo "\n";
var_export(parse_ini_string('a="${NO_SUCH_PHPC_INI}"', false, INI_SCANNER_RAW));
echo "\n";
// NORMAL still expands (no regression)
var_export(parse_ini_string('a="${PHPC_INI_TEST}"', false, INI_SCANNER_NORMAL));
echo "\n";
putenv('PHPC_INI_TEST');
--EXPECT--
array (
  'a' => '${PHPC_INI_TEST}',
)
array (
  'a' => '${NO_SUCH_PHPC_INI}',
)
array (
  'a' => 'fromenv',
)
