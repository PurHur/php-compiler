<?php

declare(strict_types=1);

$a = ['keep' => 1, 'drop' => 2];

try {
    array_filter($a, ARRAY_FILTER_USE_KEY);
    echo "uncaught flag-as-callback\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_filter($a, ARRAY_FILTER_USE_BOTH);
    echo "uncaught both-flag-as-callback\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_filter($a, true);
    echo "uncaught bool-callback\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_filter($a, [1]);
    echo "uncaught short-array-callback\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_filter($a, 'not_a_real_function_xyz');
    echo "uncaught undefined-string-callback\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
