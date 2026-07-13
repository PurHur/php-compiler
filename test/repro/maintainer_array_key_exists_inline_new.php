<?php

declare(strict_types=1);

$expected = 'array_key_exists(): Argument #2 ($array) must be of type array, ArrayObject given';

try {
    array_key_exists(0, new ArrayObject([1]));
    fwrite(STDERR, "inline new: expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, 'inline new: '.$e->getMessage()."\n");
        exit(1);
    }
    echo "inline new: ok\n";
}

$o = new ArrayObject([1]);
try {
    array_key_exists(0, $o);
    fwrite(STDERR, "variable: expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, 'variable: '.$e->getMessage()."\n");
        exit(1);
    }
    echo "variable: ok\n";
}
