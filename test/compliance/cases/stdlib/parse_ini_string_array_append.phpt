--TEST--
stdlib parse_ini_string() key[]=value — nested array append (#12929, ext/standard/ini.c)
--FILE--
<?php
var_export(parse_ini_string("a[]=1\na[]=2"));
echo "\n";
var_export(parse_ini_string("[s]\na[]=1\na[]=2", true));
?>
--EXPECT--
array (
  'a' => array (
    0 => '1',
    1 => '2',
  ),
)
array (
  's' => array (
    'a' => array (
      0 => '1',
      1 => '2',
    ),
  ),
)
