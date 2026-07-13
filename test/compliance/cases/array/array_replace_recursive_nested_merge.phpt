--TEST--
array_replace_recursive() preserves nested sibling keys on assigned locals (#18571)
--FILE--
<?php
$a = ['x' => ['a' => 1]];
$b = ['x' => ['b' => 2]];
var_export(array_replace_recursive($a, $b));
echo "\n";
$a = ['k' => ['x' => 1, 'y' => 2]];
$b = ['k' => ['y' => 9]];
var_export(array_replace_recursive($a, $b));
echo "\n";
$a = ['l' => ['a' => 1, 'b' => ['c' => 3]]];
$b = ['l' => ['b' => ['d' => 4]]];
var_export(array_replace_recursive($a, $b));
echo "\n";
?>
--EXPECT--
array (
  'x' => 
  array (
    'a' => 1,
    'b' => 2,
  ),
)
array (
  'k' => 
  array (
    'x' => 1,
    'y' => 9,
  ),
)
array (
  'l' => 
  array (
    'a' => 1,
    'b' => 
    array (
      'c' => 3,
      'd' => 4,
    ),
  ),
)
