<?php

declare(strict_types=1);

try {
    file_put_contents(sys_get_temp_dir().'/phpc_flags_test.txt', 'x', 'LOCK_EX');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
