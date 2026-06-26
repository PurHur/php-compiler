<?php
declare(strict_types=1);
foreach (['bindec', 'hexdec', 'octdec'] as $fn) {
    try {
        echo "$fn(101)=" . $fn(101) . "\n";
    } catch (Throwable $e) {
        echo "$fn(101)=EX:" . get_class($e) . ':' . $e->getMessage() . "\n";
    }
}
