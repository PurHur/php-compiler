<?php

declare(strict_types=1);

try {
    var_export(get_class(null));
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
