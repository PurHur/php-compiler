<?php

declare(strict_types=1);

$cases = [
    ['printf', 0, 'printf() expects at least 1 argument, 0 given', static fn () => printf()],
    ['sprintf', 0, 'sprintf() expects at least 1 argument, 0 given', static fn () => sprintf()],
    ['pack', 0, 'pack() expects at least 1 argument, 0 given', static fn () => pack()],
    ['unpack', 1, 'unpack() expects at least 2 arguments, 1 given', static fn () => unpack('I')],
];

foreach ($cases as [$fn, $argc, $expected, $invoke]) {
    try {
        $invoke();
        fwrite(STDERR, "fail: {$fn}() {$argc}-args no exception\n");
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
