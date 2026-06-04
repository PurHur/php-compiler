--TEST--
Language: (array) cast on unit enum case — name key not 0 (#5536, zend_enum.c)
--FILE--
<?php
enum E { case A; }
var_export((array) E::A);
echo "\n";
--EXPECT--
array (
  'name' => 'A',
)
