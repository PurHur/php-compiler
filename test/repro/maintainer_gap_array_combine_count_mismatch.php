<?php

declare(strict_types=1);

// Issue #16080 — array_combine() unequal key/value counts must throw ValueError (ext/standard/array.c).
try {
    array_combine(['a'], [1, 2]);
    echo "fail: no exception\n";
    exit(1);
} catch (ValueError $e) {
    echo "ok: ValueError\n";
    echo $e->getMessage(), "\n";
    exit(0);
}
