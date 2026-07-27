<?php

declare(strict_types=1);

/**
 * Maintainer repro: array_find family — Zend has no $strict third arg (#23875; was #6949).
 */

$haystack = [1, '1', 2];

if (array_find($haystack, fn ($v) => $v == 1) !== 1) {
    echo "fail: loose array_find\n";
    exit(1);
}

try {
    array_find($haystack, fn ($v) => $v == 1 ? 1 : 0, true);
    echo "fail: array_find accepted 3rd arg\n";
    exit(1);
} catch (ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), 'expects exactly 2 arguments, 3 given')) {
        echo 'fail: array_find message: ', $e->getMessage(), "\n";
        exit(1);
    }
}

if (array_find_key($haystack, fn ($v) => $v == 1) !== 0) {
    echo "fail: loose array_find_key\n";
    exit(1);
}

try {
    array_find_key($haystack, fn ($v) => $v == 1 ? 1 : 0, true);
    echo "fail: array_find_key accepted 3rd arg\n";
    exit(1);
} catch (ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), 'expects exactly 2 arguments, 3 given')) {
        echo 'fail: array_find_key message: ', $e->getMessage(), "\n";
        exit(1);
    }
}

$h = ['a' => 1, 'b' => '1'];
if (!array_all($h, fn ($v, $k) => $v == 1)) {
    echo "fail: array_all loose\n";
    exit(1);
}
if (!array_all($h, fn ($v, $k) => $v == 1 ? 1 : 0)) {
    echo "fail: array_all truthy-int\n";
    exit(1);
}
if (!array_any($h, fn ($v, $k) => $v == 1 ? 1 : 0)) {
    echo "fail: array_any truthy-int\n";
    exit(1);
}

try {
    array_all($h, fn ($v, $k) => $v == 1, true);
    echo "fail: array_all accepted 3rd arg\n";
    exit(1);
} catch (ArgumentCountError $e) {
}

try {
    array_any($h, fn ($v, $k) => $v == 1, true);
    echo "fail: array_any accepted 3rd arg\n";
    exit(1);
} catch (ArgumentCountError $e) {
}

echo "ok\n";
