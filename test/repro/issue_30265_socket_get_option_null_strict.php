<?php
declare(strict_types=1);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    $r = @socket_get_option($s, null, SO_REUSEADDR);
    echo 'OK: ', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    @socket_set_option($s, null, SO_REUSEADDR, 1);
    echo "set_option OK\n";
} catch (Throwable $e) {
    echo 'set_option: ', get_class($e), ': ', $e->getMessage(), "\n";
}
