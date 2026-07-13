<?php

declare(strict_types=1);

try {
    array_key_exists(0, new ArrayObject([1]));
    echo "no_throw\n";
    exit(1);
} catch (\TypeError $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'ArrayObject') && !str_contains($msg, 'null')) {
        echo "ok:{$msg}\n";
        exit(0);
    }
    echo "fail:{$msg}\n";
    exit(1);
}
