--TEST--
stdlib parse_ini_string() JIT named process_sections/scanner_mode (#17090)
--JIT--
--FILE--
<?php
var_export(parse_ini_string('a=1', process_sections: false));
echo "\n";
var_export(parse_ini_string('n=42', scanner_mode: INI_SCANNER_TYPED));
echo "\n";
?>
--EXPECT--
array (
  'a' => '1',
)
array (
  'n' => 42,
)
