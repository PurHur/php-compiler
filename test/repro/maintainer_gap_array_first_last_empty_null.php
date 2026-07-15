<?php

declare(strict_types=1);

if (!function_exists('array_first') || !function_exists('array_last')) {
    echo "skip: array_first/array_last not on this profile\n";
    exit(0);
}

$fail = 0;

if (null !== array_first([])) {
    echo "fail: array_first([]) expected NULL\n";
    ++$fail;
}
if (null !== array_last([])) {
    echo "fail: array_last([]) expected NULL\n";
    ++$fail;
}
if (1 !== array_first([1, 2, 3])) {
    echo "fail: array_first([1,2,3]) expected 1\n";
    ++$fail;
}
if (3 !== array_last([1, 2, 3])) {
    echo "fail: array_last([1,2,3]) expected 3\n";
    ++$fail;
}

echo 0 === $fail ? "first_null\nlast_null\nok\n" : "fail\n";
