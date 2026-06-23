<?php

declare(strict_types=1);

// Issue #10819 — array_walk* by-ref Error must name $array not $param1 (ext/standard/array.c).

try {
    array_walk([1], null);
} catch (Error $e) {
    echo 'walk: ', $e->getMessage(), "\n";
}

try {
    array_walk_recursive([1], null);
} catch (Error $e) {
    echo 'rec: ', $e->getMessage(), "\n";
}
