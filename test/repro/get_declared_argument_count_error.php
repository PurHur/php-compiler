<?php

declare(strict_types=1);

foreach (['get_declared_classes', 'get_declared_traits', 'get_declared_interfaces'] as $fn) {
    try {
        $fn(1);
        echo $fn, ": no_error\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
