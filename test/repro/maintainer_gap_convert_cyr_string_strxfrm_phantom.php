<?php

declare(strict_types=1);

// Issue #11907 — PHP 8.0 removed convert_cyr_string/strxfrm must not phantom on 8.2 reference profile.
$phantoms = [];
foreach (['convert_cyr_string', 'strxfrm'] as $fn) {
    if (function_exists($fn)) {
        $phantoms[] = $fn;
    }
}

if ([] !== $phantoms) {
    echo 'fail: function_exists true for '.implode(', ', $phantoms).' on reference profile';
    exit(1);
}

echo "ok\n";
