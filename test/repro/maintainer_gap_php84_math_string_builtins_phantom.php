<?php

declare(strict_types=1);

// Issue #11846 — PHP 8.3+/8.4+ builtins must not phantom on Zend 8.2 reference profile.
$phantoms = [];
foreach (['str_increment', 'str_decrement', 'fpow'] as $fn) {
    if (function_exists($fn)) {
        $phantoms[] = $fn;
    }
}

if ([] !== $phantoms) {
    echo 'fail: function_exists true for '.implode(', ', $phantoms).' on reference profile';
    exit(1);
}

echo "ok\n";
