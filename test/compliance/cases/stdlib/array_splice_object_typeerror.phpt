--TEST--
stdlib array_splice() — object operand TypeError (#15208, ext/standard/array.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$expectedNotice = 'Only variables should be passed by reference';
try {
    array_splice(new stdClass(), 1, 1);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$last = error_get_last();
echo 'notice: ', (null !== $last && str_contains($last['message'], $expectedNotice)) ? 'yes' : 'no', "\n";
--EXPECTF--
PHP Notice:  Only variables should be passed by reference in %s on line %d
array_splice(): Argument #1 ($array) must be of type array, stdClass given
notice: yes
