<?php

declare(strict_types=1);

try {
    mb_internal_encoding('not-a-real-encoding');
    echo "no exception\n";
} catch (\ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
} catch (\Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo mb_internal_encoding(), "\n";
var_export(mb_internal_encoding('UTF-8'));
echo "\n";
echo mb_internal_encoding(), "\n";
