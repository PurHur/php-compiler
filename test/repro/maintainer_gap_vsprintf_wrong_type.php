<?php

declare(strict_types=1);

try {
    vsprintf('%s', 'hi');
} catch (Throwable $e) {
    echo 'vsprintf: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    vprintf('%s', 'hi');
} catch (Throwable $e) {
    echo 'vprintf: ', get_class($e), ': ', $e->getMessage(), "\n";
}
