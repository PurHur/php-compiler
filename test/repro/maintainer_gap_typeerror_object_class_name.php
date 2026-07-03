<?php

declare(strict_types=1);

$checks = [
    static function (): bool {
        try {
            array_fill(new stdClass(), 2, 0);
        } catch (TypeError $e) {
            return str_contains($e->getMessage(), 'stdClass given');
        }

        return false;
    },
    static function (): bool {
        try {
            array_map('strlen', new stdClass());
        } catch (TypeError $e) {
            return str_contains($e->getMessage(), 'stdClass given');
        }

        return false;
    },
];

foreach ($checks as $check) {
    if (!$check()) {
        echo "fail\n";
        exit(1);
    }
}

echo "ok\n";
