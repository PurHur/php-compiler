<?php

declare(strict_types=1);

$cases = [
    ['class_implements', [], 'class_implements() expects at least 1 argument, 0 given'],
    ['get_class_vars', [], 'get_class_vars() expects exactly 1 argument, 0 given'],
    ['get_object_vars', [], 'get_object_vars() expects exactly 1 argument, 0 given'],
    ['call_user_func', [], 'call_user_func() expects at least 1 argument, 0 given'],
    ['call_user_func_array', [], 'call_user_func_array() expects exactly 2 arguments, 0 given'],
];

foreach ($cases as [$fn, $args, $expected]) {
    try {
        $fn(...$args);
        fwrite(STDERR, "fail: {$fn}() zero-arg no exception\n");
        exit(1);
    } catch (ArgumentCountError $e) {
        if ($e->getMessage() !== $expected) {
            fwrite(STDERR, "fail: {$fn}() message={$e->getMessage()}\n");
            exit(1);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'fail: '.$fn.'() '.get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
