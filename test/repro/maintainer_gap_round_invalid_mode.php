<?php

declare(strict_types=1);

try {
    round(1.5, 0, 99);
    echo "fail: no exception\n";
    exit(1);
} catch (ValueError $e) {
    echo 'ok: ', $e->getMessage(), "\n";
}
