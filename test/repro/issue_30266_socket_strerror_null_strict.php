<?php
declare(strict_types=1);
try {
    var_dump(socket_strerror(null));
} catch (Throwable $e) {
    echo 'CATCH ', get_class($e), ': ', $e->getMessage(), "\n";
}
