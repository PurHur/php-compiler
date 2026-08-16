<?php
declare(strict_types=1);
// Maintainer gap probe: token_name(null) under strict_types.
// Zend: TypeError Argument #1 ($id) must be of type int, null given
// VM (2026-08-16): returns 'UNKNOWN' (null coerced via toInt → 0)
var_export(token_name(null));
echo "\n";
