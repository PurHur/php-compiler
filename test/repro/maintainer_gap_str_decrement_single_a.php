<?php

declare(strict_types=1);

// Issue #10828 — str_decrement('a') underflow must match Zend ValueError (ext/standard/string.c).

try {
    str_decrement('a');
    echo "no_exception\n";
} catch (ValueError $e) {
    echo "ValueError\n";
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
