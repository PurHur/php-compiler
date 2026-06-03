<?php

declare(strict_types=1);

try {
    var_export(get_object_vars(null));
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
