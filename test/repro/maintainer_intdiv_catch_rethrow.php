<?php

echo "bare catch:\n";
try {
    intdiv(1, 0);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "catch rethrow:\n";
try {
    try {
        intdiv(1, 0);
    } catch (Throwable $e) {
        throw $e;
    }
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "single catch throw new:\n";
try {
    throw new DivisionByZeroError('Division by zero');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
