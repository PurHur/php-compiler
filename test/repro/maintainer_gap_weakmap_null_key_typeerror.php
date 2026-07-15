<?php

declare(strict_types=1);

try {
    $map = new WeakMap();
    $map[null] = 1;
    echo "fail: expected TypeError\n";
    exit(1);
} catch (TypeError $e) {
    if ('WeakMap key must be an object' !== $e->getMessage()) {
        echo 'fail: got '.$e->getMessage()."\n";
        exit(1);
    }
    echo "ok\n";
    exit(0);
} catch (Error $e) {
    echo 'fail: got Error: '.$e->getMessage()."\n";
    exit(1);
}
