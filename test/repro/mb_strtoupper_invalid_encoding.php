<?php

declare(strict_types=1);

try {
    mb_strtoupper('hello', 'tr_TR');
    echo "no exception\n";
} catch (\ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
} catch (\Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    mb_strtolower('hello', 'tr_TR');
    echo "no exception\n";
} catch (\ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
} catch (\Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo mb_strtoupper('hello', 'UTF-8'), "\n";
