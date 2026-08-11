<?php
declare(strict_types=1);
try {
    $r = @socket_create_listen(null);
    echo 'OK: ', (is_object($r) || is_resource($r)) ? 'handle' : var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $r = @socket_create_listen(0, null);
    echo 'backlog OK: ', (is_object($r) || is_resource($r)) ? 'handle' : var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'backlog: ', get_class($e), ': ', $e->getMessage(), "\n";
}
