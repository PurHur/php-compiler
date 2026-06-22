--TEST--
stdlib parse_ini_string() — literal bool $process_sections (#9260, ext/standard/ini.c)
--FILE--
<?php
var_export(parse_ini_string("a=1\nb=2", false, INI_SCANNER_NORMAL));
echo "\n";
$ps = false;
var_export(parse_ini_string("a=1\nb=2", $ps, INI_SCANNER_NORMAL));
echo "\n";
var_export(parse_ini_string("a=1\n[sec]\nb=2", true, INI_SCANNER_NORMAL));
echo "\n";
--EXPECT--
array (
  'a' => '1',
  'b' => '2',
)
array (
  'a' => '1',
  'b' => '2',
)
array (
  'a' => '1',
  'sec' => array (
    'b' => '2',
  ),
)
