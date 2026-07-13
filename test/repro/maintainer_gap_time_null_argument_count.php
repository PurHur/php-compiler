<?php

foreach (['time(null)' => static fn () => time(null), 'time(1)' => static fn () => time(1)] as $label => $call) {
    try {
        $call();
        fwrite(STDERR, "$label: expected ArgumentCountError\n");
        exit(1);
    } catch (ArgumentCountError $e) {
        if (!str_contains($e->getMessage(), 'time() expects exactly 0 arguments')) {
            fwrite(STDERR, "$label: bad message: {$e->getMessage()}\n");
            exit(1);
        }
        echo "$label: ok\n";
    }
}
