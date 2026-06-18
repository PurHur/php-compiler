<?php

function f(?int $x): int {
    return $x ?? throw new RuntimeException('x');
}

try {
    var_dump(f(null));
} catch (RuntimeException $e) {
    echo "caught: ", $e->getMessage(), "\n";
}

// ternary / elvis form
try {
    $y = 0 ?: throw new RuntimeException('y');
    var_dump($y);
} catch (RuntimeException $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
