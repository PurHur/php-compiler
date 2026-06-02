<?php

foreach ([null, true, false, 1.5, '123', 123, [], new stdClass()] as $x) {
    try {
        echo 'crc32(';
        var_export($x);
        echo ') = ';
        echo crc32($x), "\n";
    } catch (Throwable $e) {
        echo 'crc32(';
        var_export($x);
        echo ') = ', $e::class, ': ', $e->getMessage(), "\n";
    }
}
