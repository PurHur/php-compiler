<?php

declare(strict_types=1);

$cases = [
    ['array_push', 0, 'array_push() expects at least 1 argument, 0 given'],
    ['array_slice', 0, 'array_slice() expects at least 2 arguments, 0 given'],
    ['array_splice', 0, 'array_splice() expects at least 2 arguments, 0 given'],
];

foreach ($cases as [$fn, $argc, $expected]) {
    try {
        $fn();
        fwrite(STDERR, "fail: {$fn}() zero-args no exception\n");
        exit(1);
    } catch (\ArgumentCountError $e) {
        if ($e->getMessage() !== $expected) {
            fwrite(STDERR, "fail: {$fn}() message={$e->getMessage()}\n");
            exit(1);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, 'fail: '.$fn.'() '.get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
