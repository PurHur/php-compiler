<?php

declare(strict_types=1);

try {
    reset([]);
    echo "reset: no throw\n";
} catch (Throwable $e) {
    echo 'reset: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    end([]);
    echo "end: no throw\n";
} catch (Throwable $e) {
    echo 'end: ', get_class($e), ': ', $e->getMessage(), "\n";
}

$a = [1, 2];
echo 'var reset: ', reset($a), ' end: ', end($a), "\n";
