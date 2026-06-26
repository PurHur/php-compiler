<?php

declare(strict_types=1);

// Issue #11845 — PHP 8.4 array search helpers must not phantom on Zend 8.2 reference profile.
$phantoms = [];
foreach (['array_all', 'array_any', 'array_find', 'array_find_key', 'array_first', 'array_last'] as $fn) {
    if (function_exists($fn)) {
        $phantoms[] = $fn;
    }
}

if ([] !== $phantoms) {
    echo 'fail: function_exists true for '.implode(', ', $phantoms).' on reference profile';
    exit(1);
}

echo "ok\n";
