<?php
declare(strict_types=1);
try {
    echo chr(65.9) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
