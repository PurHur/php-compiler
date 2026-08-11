<?php
declare(strict_types=1);
try {
    var_export(stream_socket_server(null));
    echo "\nNO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
