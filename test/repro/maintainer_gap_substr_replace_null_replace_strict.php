<?php
declare(strict_types=1);

try {
    var_dump(substr_replace('abc', null, 1));
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
