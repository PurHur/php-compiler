<?php
declare(strict_types=1);
try {
    var_export(token_get_all(null));
    echo "\nNO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
