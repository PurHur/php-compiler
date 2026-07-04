<?php

declare(strict_types=1);

foreach (['str_increment', 'str_decrement'] as $fn) {
    try {
        $fn('');
    } catch (Throwable $e) {
        if (!$e instanceof Error) {
            echo $fn, ': fail expected Error got ', get_class($e), "\n";
            exit(1);
        }
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

echo "ok\n";
