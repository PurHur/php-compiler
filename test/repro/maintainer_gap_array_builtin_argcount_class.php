<?php

declare(strict_types=1);

foreach (['array_push', 'array_slice', 'array_splice'] as $fn) {
    try {
        $fn();
        echo "{$fn}: NO_ERROR\n";
    } catch (ArgumentCountError $e) {
        echo "{$fn}: ArgumentCountError: {$e->getMessage()}\n";
    } catch (Throwable $e) {
        echo "{$fn}: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}

echo "ok\n";
