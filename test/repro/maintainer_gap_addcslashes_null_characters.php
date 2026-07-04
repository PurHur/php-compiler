<?php

declare(strict_types=1);

try {
    addcslashes('abc', null);
    echo "fail: addcslashes('abc', null) accepted null — Zend requires TypeError\n";
    exit(1);
} catch (TypeError $e) {
    $expected = 'addcslashes(): Argument #2 ($charlist) must be of type string, null given';
    if ($expected !== $e->getMessage()) {
        echo 'fail: addcslashes(null charlist) got ', $e->getMessage(), "\n";
        exit(1);
    }
}
echo "ok\n";
