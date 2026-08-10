<?php
declare(strict_types=1);

try {
    var_export(http_response_code(null));
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
