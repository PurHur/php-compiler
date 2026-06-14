--TEST--
stdlib asort() mixed string and integer values (#4461, ext/standard/array.c)
--FILE--
<?php
$b = ['x' => 10, 'y' => '2', 'z' => 3];
asort($b);
var_export($b);
echo "\n";
--EXPECT--
array (
  'y' => '2',
  'z' => 3,
  'x' => 10,
)
