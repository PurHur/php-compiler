<?php

declare(strict_types=1);

foreach ([123, 'abc'] as $x) {
    try {
        echo 'strict crc32(';
        var_export($x);
        echo ') = ';
        echo crc32($x), "\n";
    } catch (Throwable $e) {
        echo 'strict crc32(';
        var_export($x);
        echo ') = ', $e::class, ': ', $e->getMessage(), "\n";
    }
}
