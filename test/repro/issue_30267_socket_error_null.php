<?php
declare(strict_types=1);
try {
    socket_clear_error(null);
    echo "clear: OK\n";
} catch (Throwable $e) {
    echo 'clear: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_dump(socket_last_error(null));
} catch (Throwable $e) {
    echo 'last: ', get_class($e), ': ', $e->getMessage(), "\n";
}
