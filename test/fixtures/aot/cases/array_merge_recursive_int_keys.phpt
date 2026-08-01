--TEST--
AOT: array_merge_recursive() renumbers int keys (#26559)
--FILE--
<?php
var_export(array_merge_recursive([1 => 'a'], [1 => 'b']));
echo "\n";
var_export(array_merge_recursive(['k' => [1 => 'a']], ['k' => [1 => 'b']]));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
array (
  'k' => array (
    1 => 'a',
    2 => 'b',
  ),
)
