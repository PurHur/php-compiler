<?php
declare(strict_types=1);
try {
    echo intdiv(10, 3.0) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
