<?php
declare(strict_types=1);

try {
    var_dump(substr_replace('abcd', 'X', null));
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
