--TEST--
stdlib parse_ini_string() NORMAL expands unquoted ${ENV} (#23564, ext/standard/ini.c)
--FILE--
<?php
declare(strict_types=1);

putenv('PHPC_INI_TEST=fromenv');
var_export(parse_ini_string('a=${PHPC_INI_TEST}', false, INI_SCANNER_NORMAL));
echo "\n";
var_export(parse_ini_string('a=pre ${PHPC_INI_TEST} post', false, INI_SCANNER_NORMAL));
echo "\n";
var_export(parse_ini_string('a=${NO_SUCH_PHPC_INI}', false, INI_SCANNER_NORMAL));
echo "\n";
// RAW unquoted stays literal
var_export(parse_ini_string('a=${PHPC_INI_TEST}', false, INI_SCANNER_RAW));
echo "\n";
putenv('PHPC_INI_TEST');
--EXPECT--
array (
  'a' => 'fromenv',
)
array (
  'a' => 'pre fromenv post',
)
array (
  'a' => '',
)
array (
  'a' => '${PHPC_INI_TEST}',
)
