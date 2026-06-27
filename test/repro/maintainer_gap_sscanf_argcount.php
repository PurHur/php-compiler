<?php

declare(strict_types=1);

try {
    sscanf('42');
    fwrite(STDERR, "sscanf: expected ArgumentCountError\n");
    exit(1);
} catch (ArgumentCountError $e) {
    if ('sscanf() expects at least 2 arguments, 1 given' !== $e->getMessage()) {
        fwrite(STDERR, 'sscanf: unexpected message: '.$e->getMessage()."\n");
        exit(1);
    }
    echo "ok\n";
}
