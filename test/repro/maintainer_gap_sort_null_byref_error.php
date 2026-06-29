<?php

declare(strict_types=1);

// php-src: sort(null) literal → by-ref Error before array type check (#13408, ext/standard/array.c).

$expected = 'sort(): Argument #1 ($array) cannot be passed by reference';

try {
    sort(null);
    echo "fail: sort(null) uncaught\n";
    exit(1);
} catch (Error $e) {
    if ($expected !== $e->getMessage()) {
        echo 'fail: sort(null) got ', $e->getMessage(), "\n";
        exit(1);
    }
} catch (TypeError $e) {
    echo 'fail: sort(null) TypeError: ', $e->getMessage(), "\n";
    exit(1);
}

$arr = [];
if (!sort($arr)) {
    echo "fail: sort([]) must succeed\n";
    exit(1);
}

echo "ok\n";
