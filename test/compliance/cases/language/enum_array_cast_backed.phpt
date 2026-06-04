--TEST--
Language: (array) cast on backed enum case — name and value keys (#5536, zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; }
var_export((array) E::A);
echo "\n";
--EXPECT--
array (
  'name' => 'A',
  'value' => 1,
)
