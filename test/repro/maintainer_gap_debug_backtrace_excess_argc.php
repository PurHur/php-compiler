<?php

declare(strict_types=1);

foreach (['debug_backtrace', 'debug_print_backtrace'] as $fn) {
    try {
        $fn(0, 0, 0);
        echo $fn, ": NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
