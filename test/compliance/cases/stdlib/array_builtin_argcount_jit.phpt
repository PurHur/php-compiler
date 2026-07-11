--TEST--
stdlib JIT array_push/array_slice/array_splice zero-args ArgumentCountError (#17906, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

try {
    array_push();
} catch (ArgumentCountError $e) {
    echo 'array_push: ', $e->getMessage(), "\n";
}

try {
    array_slice();
} catch (ArgumentCountError $e) {
    echo 'array_slice: ', $e->getMessage(), "\n";
}

try {
    array_splice();
} catch (ArgumentCountError $e) {
    echo 'array_splice: ', $e->getMessage(), "\n";
}

echo "ok\n";
--EXPECT--
array_push: array_push() expects at least 1 argument, 0 given
array_slice: array_slice() expects at least 2 arguments, 0 given
array_splice: array_splice() expects at least 2 arguments, 0 given
ok
