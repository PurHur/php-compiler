--TEST--
stdlib array_merge family — object operand TypeError (#15858, ext/standard/array.c)
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
        echo "uncaught:{$fn}\n";
        exit(1);
    } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be of type array')) {
            echo "bad-msg:{$fn}:{$e->getMessage()}\n";
            exit(1);
        }
    }
}

echo "ok\n";
--EXPECT--
ok
