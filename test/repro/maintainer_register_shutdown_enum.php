<?php

declare(strict_types=1);

/**
 * Issue #6245: register_shutdown_function() enum case callback must TypeError like Zend.
 */
enum E: string
{
    case A = 'x';
}

try {
    register_shutdown_function(E::A);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
