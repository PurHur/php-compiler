<?php

declare(strict_types=1);

$arr = [1];
try {
    array_walk($arr, 'intval', 'u');
    echo "fail\n";
    exit(1);
} catch (ArgumentCountError $e) {
    // Zend: "intval() expects exactly 1 argument, 3 given"
    echo "ok\n";
}

