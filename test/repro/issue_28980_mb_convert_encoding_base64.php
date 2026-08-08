<?php

declare(strict_types=1);

/**
 * Repro #28980 — mb_convert_encoding() BASE64 pseudo-encoding (php-src ext/mbstring/mbstring.c).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    if (str_contains($m, 'Handling Base64 via mbstring is deprecated')) {
        echo "DEP\n";
    }

    return true;
});

try {
    var_export(mb_convert_encoding('Hello, 世界', 'BASE64'));
    echo "\n";
    var_export(mb_convert_encoding('QQ==', 'UTF-8', 'BASE64'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
