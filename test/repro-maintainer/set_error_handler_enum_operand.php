<?php

declare(strict_types=1);

/**
 * Issue #6234: set_error_handler() enum case callback must TypeError like Zend.
 */
enum E: string
{
    case A = 'x';
}

try {
    set_error_handler(E::A);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
