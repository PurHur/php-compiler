--TEST--
stdlib array_merge/replace/intersect/diff — single-array variadic calls (#4620)
--FILE--
<?php
var_export(array_merge([1 => 'a']));
echo "\n";
var_export(array_replace([1 => 'a']));
echo "\n";
var_export(array_intersect([1, 2]));
echo "\n";
var_export(array_diff([1, 2]));
echo "\n";
var_export(array_merge_recursive([1 => 'a']));
echo "\n";
var_export(array_replace_recursive([1 => 'a']));
echo "\n";
try {
    array_merge();
} catch (\ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array (
  0 => 'a',
)
array (
  1 => 'a',
)
array (
  0 => 1,
  1 => 2,
)
array (
  0 => 1,
  1 => 2,
)
array (
  0 => 'a',
)
array (
  1 => 'a',
)
array_merge() expects at least 1 argument, 0 given
