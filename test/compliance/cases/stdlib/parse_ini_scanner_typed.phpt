--TEST--
stdlib parse_ini_string() INI_SCANNER_TYPED + INI_SCANNER_RAW (#9153, ext/standard/ini.c)
--FILE--
<?php
var_export(parse_ini_string("n=42\nf=3.14\nb=1", false, INI_SCANNER_TYPED));
echo "\n";
var_export(parse_ini_string("t=true\nv=hello", false, INI_SCANNER_RAW));
echo "\n";
var_export(parse_ini_string("k=null", false, INI_SCANNER_TYPED));
echo "\n";
--EXPECT--
array (
  'n' => 42,
  'f' => 3.14,
  'b' => 1,
)
array (
  't' => 'true',
  'v' => 'hello',
)
array (
  'k' => NULL,
)
