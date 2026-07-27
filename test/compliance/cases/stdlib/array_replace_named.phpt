--TEST--
stdlib array_replace()/array_merge() variadic named — overwrite Error (#11349, #23804, ext/standard/array.c)
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
try {
    array_merge(arrays: [1], arrays: [2]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_replace(array: [1 => 'a'], array: [1 => 'b']);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_export(array_replace([1], [2]));
echo "\n";
var_export(array_merge([1], [2]));
echo "\n";
--EXPECT--
ArgumentCountError: array_replace() does not accept unknown named parameters
ArgumentCountError: array_merge() does not accept unknown named parameters
Error: Named parameter $arrays overwrites previous argument
Error: Named parameter $array overwrites previous argument
array (
  0 => 2,
)
array (
  0 => 1,
  1 => 2,
)
