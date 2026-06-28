--TEST--
AOT parse_ini_string() key[]=value — nested array append (#12929)
--FILE--
<?php
var_export(parse_ini_string("a[]=1\na[]=2"));
?>
--EXPECT--
array (
  'a' => array (
    0 => '1',
    1 => '2',
  ),
)
