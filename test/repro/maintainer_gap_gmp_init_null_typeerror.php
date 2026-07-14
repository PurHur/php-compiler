<?php
// gmp_init(null) must not fatal — php-src Z_PARAM_STR_OR_LONG coerces null to 0 (#18946).
$z = gmp_init(null);
echo gmp_strval($z), "\n";
echo gmp_strval(gmp_add($z, gmp_init('1'))), "\n";
