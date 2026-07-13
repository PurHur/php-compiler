<?php

function f(int ...$args): int {
    return array_sum($args);
}

echo f(a: 1, b: 2, c: 3), "\n";

try {
    f(['a' => 1]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    f(a: 'bad');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
