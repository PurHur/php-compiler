<?php

declare(strict_types=1);

foreach (['get_meta_tags', 'get_headers'] as $fn) {
    try {
        $fn('');
        echo "fail: {$fn}(\"\") expected ValueError\n";
        exit(1);
    } catch (ValueError $e) {
        if ('Path cannot be empty' !== $e->getMessage()) {
            echo "fail: {$fn}(): {$e->getMessage()}\n";
            exit(1);
        }
    }
}
echo "ok\n";
