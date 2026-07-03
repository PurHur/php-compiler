<?php

declare(strict_types=1);

try {
    array_uintersect_uassoc([1 => 'a'], [1 => 'b'], 'strcmp');
    fwrite(STDERR, "expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'Argument #2')) {
        fwrite(STDERR, 'unexpected message: '.$e->getMessage()."\n");
        exit(1);
    }
    echo $e->getMessage(), "\n";
}

try {
    array_uintersect_uassoc([1 => 'a'], [1 => 'b']);
    fwrite(STDERR, "expected ArgumentCountError\n");
    exit(1);
} catch (ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), 'expects at least 3 arguments')) {
        fwrite(STDERR, 'unexpected message: '.$e->getMessage()."\n");
        exit(1);
    }
    echo $e->getMessage(), "\n";
}

echo "ok\n";
