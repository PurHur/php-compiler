--TEST--
stdlib array_replace()/array_merge() variadic named — ArgumentCountError (#11349, ext/standard/array.c)
--FILE--
<?php
try {
    array_replace(array: [1], arrays: [2]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_merge(array: [1], arrays: [2]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_export(array_replace([1], [2]));
echo "\n";
--EXPECT--
ArgumentCountError: array_replace() does not accept unknown named parameters
ArgumentCountError: array_merge() does not accept unknown named parameters
array (
  0 => 2,
)
