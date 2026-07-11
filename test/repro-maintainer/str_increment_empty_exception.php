<?php

declare(strict_types=1);

foreach (['str_increment', 'str_decrement'] as $fn) {
    try {
        $fn('');
    } catch (Throwable $e) {
        if (!$e instanceof ValueError) {
            echo $fn, ': fail expected ValueError got ', get_class($e), "\n";
            exit(1);
        }
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

echo "ok\n";
