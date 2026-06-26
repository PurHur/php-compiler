<?php
declare(strict_types=1);
foreach (['dechex', 'decoct', 'decbin'] as $fn) {
    try {
        echo "$fn(65.9)=" . $fn(65.9) . "\n";
    } catch (Throwable $e) {
        echo "$fn(65.9)=EX:" . get_class($e) . ':' . $e->getMessage() . "\n";
    }
}
