<?php

declare(strict_types=1);

$f = sys_get_temp_dir().'/phpc_chmod_'.uniqid('', true).'.tmp';
touch($f);
try {
    chmod($f, '0644');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
} finally {
    @unlink($f);
}
