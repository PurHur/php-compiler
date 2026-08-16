<?php
declare(strict_types=1);
// Maintainer gap probe / #31406: define(..., null) $case_insensitive under strict_types.
// Zend: TypeError Argument #3 ($case_insensitive) must be of type bool, null given
$name = 'M_GAP_' . bin2hex(random_bytes(4));
try {
    var_export(define($name, 1, null));
    echo "\nDEFINED=", defined($name) ? 'yes' : 'no', "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    echo "DEFINED=", defined($name) ? 'yes' : 'no', "\n";
}
