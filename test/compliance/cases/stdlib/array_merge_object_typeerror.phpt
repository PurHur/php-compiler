--TEST--
stdlib array_merge family — object operand TypeError inline cast (#15207, ext/standard/array.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$fns = [
    'array_merge',
    'array_merge_recursive',
    'array_replace',
    'array_replace_recursive',
    'array_diff',
    'array_intersect',
];

foreach ($fns as $fn) {
    try {
        $fn((object) ['a' => 1], ['b' => 2]);
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
--EXPECT--
array_merge: array_merge(): Argument #1 must be of type array, stdClass given
array_merge_recursive: array_merge_recursive(): Argument #1 must be of type array, stdClass given
array_replace: array_replace(): Argument #1 ($array) must be of type array, stdClass given
array_replace_recursive: array_replace_recursive(): Argument #1 must be of type array, stdClass given
array_diff: array_diff(): Argument #1 must be of type array, stdClass given
array_intersect: array_intersect(): Argument #1 must be of type array, stdClass given
