<?php

declare(strict_types=1);

try {
    var_export(date_parse_from_format(null, 'Y'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(date_parse_from_format('Y', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
