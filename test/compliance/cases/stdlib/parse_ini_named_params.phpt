--TEST--
stdlib parse_ini_string()/parse_ini_file() named process_sections/scanner_mode (#17090)
--FILE--
<?php
var_export(parse_ini_string('x=1', process_sections: true));
echo "\n";
var_export(parse_ini_string('true', scanner_mode: INI_SCANNER_TYPED));
echo "\n";
var_export(parse_ini_string("[s]\nk=v", process_sections: true));
echo "\n";
var_export(is_array(parse_ini_file('/etc/hosts', process_sections: true)));
echo "\n";
?>
--EXPECT--
array (
  'x' => '1',
)
array (
)
array (
  's' => array (
    'k' => 'v',
  ),
)
true
