<?php

declare(strict_types=1);

try {
    printf(null);
    echo "printf: ok\n";
} catch (Throwable $e) {
    echo 'printf: ', $e::class, ': ', $e->getMessage(), "\n";
}
try {
    sprintf(null);
    echo "sprintf: ok\n";
} catch (Throwable $e) {
    echo 'sprintf: ', $e::class, ': ', $e->getMessage(), "\n";
}
