<?php

declare(strict_types=1);

try {
    var_export(getopt(null));
    echo "\nfail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
