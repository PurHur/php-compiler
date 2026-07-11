<?php

declare(strict_types=1);

try {
    preg_replace_callback('/a/', null, 'a');
    fwrite(STDERR, "unexpected_ok\n");
    exit(1);
} catch (TypeError $e) {
    $expected = 'preg_replace_callback(): Argument #2 ($callback) must be a valid callback, no array or string given';
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
