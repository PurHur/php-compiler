<?php

declare(strict_types=1);

$cases = [
    ['is_a', 'x', 'is_a() expects at least 2 arguments, 1 given'],
    ['is_subclass_of', 'x', 'is_subclass_of() expects at least 2 arguments, 1 given'],
    ['method_exists', 'x', 'method_exists() expects exactly 2 arguments, 1 given'],
    ['property_exists', 'x', 'property_exists() expects exactly 2 arguments, 1 given'],
];

foreach ($cases as [$fn, $arg, $expected]) {
    try {
        $fn($arg);
        fwrite(STDERR, "fail: {$fn}() one-arg no exception\n");
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
