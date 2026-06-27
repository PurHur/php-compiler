<?php

declare(strict_types=1);

if (!class_exists('InternalIterator')) {
    echo "fail: InternalIterator missing\n";
    exit(1);
}

try {
    new InternalIterator();
    echo "fail: InternalIterator instantiable\n";
    exit(1);
} catch (Error $e) {
    if (!str_contains($e->getMessage(), 'private InternalIterator::__construct()')) {
        echo 'fail: '.$e->getMessage()."\n";
        exit(1);
    }
}

echo "ok\n";
