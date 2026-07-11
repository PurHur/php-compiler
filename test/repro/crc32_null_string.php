<?php
declare(strict_types=1);
try {
    var_export(crc32(null));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage();
}
