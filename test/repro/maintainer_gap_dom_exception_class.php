<?php

declare(strict_types=1);

if (!class_exists('DOMException')) {
    fwrite(STDERR, "missing DOMException\n");
    exit(1);
}

try {
    throw new DOMException('probe', DOMSTRING_SIZE_ERR);
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
    echo $e->getCode(), "\n";
    echo $e instanceof Exception ? "instance_ok\n" : "instance_bad\n";
    echo "ok\n";
    exit(0);
}

fwrite(STDERR, "catch failed\n");
exit(1);
