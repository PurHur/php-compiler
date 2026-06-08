<?php
declare(strict_types=1);

try {
    pack('!', 1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    pack('cc', 1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
