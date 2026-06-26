<?php

declare(strict_types=1);

// Issue #11901 — PHP 8.4 mb_trim family must not phantom on Zend 8.2 reference profile.
$phantoms = [];
foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
    if (function_exists($fn)) {
        $phantoms[] = $fn;
    }
}

if ([] !== $phantoms) {
    echo 'fail: function_exists true for '.implode(', ', $phantoms).' on reference profile';
    exit(1);
}

echo "ok\n";
