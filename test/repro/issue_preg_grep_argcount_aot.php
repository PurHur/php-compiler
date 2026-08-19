<?php

declare(strict_types=1);

try {
    preg_grep();
    echo "zero:OK\n";
} catch (Throwable $e) {
    echo 'zero:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    preg_grep('/a/', [], 0, 1);
    echo "four:OK\n";
} catch (Throwable $e) {
    echo 'four:', get_class($e), ':', $e->getMessage(), "\n";
}
