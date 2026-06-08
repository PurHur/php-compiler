<?php

try {
    intdiv(PHP_INT_MIN, -1);
    echo "no throw\n";
} catch (ArithmeticError $e) {
    echo 'ArithmeticError: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
