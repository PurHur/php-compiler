<?php

declare(strict_types=1);

foreach (['get_declared_classes', 'get_declared_traits', 'get_declared_interfaces'] as $fn) {
    try {
        $fn(true);
        echo $fn, ": one_arg_ok\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
    try {
        $fn(true, false);
        echo $fn, ": two_arg_no_error\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
