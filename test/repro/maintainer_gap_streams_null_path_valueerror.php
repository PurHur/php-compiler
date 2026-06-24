<?php

// Issue #11016 — null path must throw ValueError: Path cannot be empty (ext/standard/streamsfuncs.c).

$checks = [
    'fopen' => static fn () => @fopen(null, 'r'),
    'file_get_contents' => static fn () => @file_get_contents(null),
    'copy' => static fn () => @copy(null, 'x'),
    'readfile' => static fn () => @readfile(null),
    'file' => static fn () => @file(null),
];

foreach ($checks as $name => $call) {
    try {
        $call();
        fwrite(STDERR, "$name: expected ValueError, got success/false\n");
        exit(1);
    } catch (\ValueError $e) {
        if ('Path cannot be empty' !== $e->getMessage()) {
            fwrite(STDERR, "$name: wrong message: {$e->getMessage()}\n");
            exit(1);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "$name: expected ValueError, got ".get_class($e).": {$e->getMessage()}\n");
        exit(1);
    }
}

echo "ok\n";
