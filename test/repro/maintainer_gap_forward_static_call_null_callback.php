<?php

declare(strict_types=1);

try {
    forward_static_call(null);
    fwrite(STDERR, "unexpected_ok\n");
    exit(1);
} catch (TypeError $e) {
    $expected = 'forward_static_call(): Argument #1 ($callback) must be a valid callback, no array or string given';
    if ($e->getMessage() !== $expected) {
        fwrite(STDERR, 'bad_message: '.$e->getMessage()."\n");
        exit(1);
    }
    echo "OK\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
