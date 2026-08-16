--TEST--
stdlib array_slice/chunk/reverse(null $preserve_keys) soft DEP+coerce (#31442, ext/standard/array.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
var_export(array_slice([1, 2, 3], 0, 1, null));
echo "\n";
var_export(array_chunk([1, 2], 1, null));
echo "\n";
var_export(array_reverse([1, 2], null));
echo "\n";
?>
--EXPECTF--
%ADeprecated: array_slice(): Passing null to parameter #4 ($preserve_keys) of type bool is deprecated in %s on line %d
array (
  0 => 1,
)
%ADeprecated: array_chunk(): Passing null to parameter #3 ($preserve_keys) of type bool is deprecated in %s on line %d
array (
  0 => array (
    0 => 1,
  ),
  1 => array (
    0 => 2,
  ),
)
%ADeprecated: array_reverse(): Passing null to parameter #2 ($preserve_keys) of type bool is deprecated in %s on line %d
array (
  0 => 2,
  1 => 1,
)
