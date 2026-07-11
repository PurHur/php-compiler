<?php

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
        echo "fail: {$fn} did not throw\n";
        exit(1);
    } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be of type array')) {
            echo "fail: {$fn} wrong message: {$e->getMessage()}\n";
            exit(1);
        }
    } catch (Throwable $e) {
        echo 'fail: ', $fn, ' ', get_class($e), ': ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
