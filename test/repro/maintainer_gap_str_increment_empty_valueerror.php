<?php

declare(strict_types=1);

foreach (['str_increment', 'str_decrement'] as $fn) {
    try {
        $fn('');
    } catch (ValueError $e) {
        if ($e->getMessage() !== $fn.'(): Argument #1 ($string) must not be empty') {
            echo $fn, ': bad message: ', $e->getMessage(), "\n";
            exit(1);
        }
        continue;
    } catch (Throwable $e) {
        echo $fn, ': fail expected ValueError got ', get_class($e), "\n";
        exit(1);
    }
    echo $fn, ": fail expected ValueError\n";
    exit(1);
}

echo "ok\n";
