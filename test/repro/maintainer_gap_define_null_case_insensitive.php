<?php
declare(strict_types=1);
// Maintainer gap probe: define(..., null) $case_insensitive under strict_types.
// Zend: TypeError Argument #3 ($case_insensitive) must be of type bool, null given
// VM (2026-08-16): returns true (coerces null via toBool)
$name = 'M_GAP_' . bin2hex(random_bytes(4));
var_export(define($name, 1, null));
echo "\n";
