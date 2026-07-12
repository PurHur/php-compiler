--TEST--
stdlib array_push/array_slice/array_splice zero-args throw ArgumentCountError (#17906, ext/standard/array.c)
--FILE--
<?php
try {
    array_push();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    array_slice();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    array_splice();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array_push() expects at least 1 argument, 0 given
array_slice() expects at least 2 arguments, 0 given
array_splice() expects at least 2 arguments, 0 given
