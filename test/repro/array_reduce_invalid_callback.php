<?php

try {
    array_reduce([], null);
    echo "uncaught null\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_reduce([], 'not_a_real_function_xyz');
    echo "uncaught string\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function sum(int $carry, int $item): int
{
    return $carry + $item;
}
echo array_reduce(array(), 'sum', 0), "\n";
echo array_reduce(array(), fn(int $carry, int $item): int => $carry + $item, 5), "\n";
