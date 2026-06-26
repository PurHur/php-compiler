<?php
declare(strict_types=1);
try {
    echo base_convert(65.9, 10, 16) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
