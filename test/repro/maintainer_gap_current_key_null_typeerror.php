<?php

declare(strict_types=1);

foreach (['current', 'key'] as $fn) {
    try {
        $fn(null);
        echo "fail: {$fn}() no TypeError\n";
        exit(1);
    } catch (TypeError $e) {
        $expected = "{$fn}(): Argument #1 (\$array) must be of type array, null given";
        if ($expected !== $e->getMessage()) {
            echo 'fail: ', $e->getMessage(), "\n";
            exit(1);
        }
    }
}

echo "ok\n";
