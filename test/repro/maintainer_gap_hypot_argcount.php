<?php
declare(strict_types=1);
try {
    hypot(3, 4, 5);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
