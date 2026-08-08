<?php

declare(strict_types=1);

set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});

@hex2bin('GG');

try {
    hex2bin('abc', true);
    echo "FAIL: expected ArgumentCountError on second arg\n";
    exit(1);
} catch (\ArgumentCountError $e) {
    echo 'E:', $e->getMessage(), "\n";
}
