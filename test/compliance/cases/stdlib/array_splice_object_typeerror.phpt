--TEST--
stdlib array_splice() — object operand TypeError (#15208, ext/standard/array.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

try {
    array_splice((object) [1, 2, 3], 1, 1);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array_splice(): Argument #1 ($array) must be of type array, stdClass given
