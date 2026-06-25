<?php
// Issue #11757 — filter_var() FILTER_VALIDATE_INT ALLOW_HEX / ALLOW_OCTAL (logical_filters.c).
$hex = filter_var('0x10', FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX);
$oct = filter_var('010', FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_OCTAL);
$plain = filter_var('0x10', FILTER_VALIDATE_INT);
if (16 !== $hex || 8 !== $oct || false !== $plain) {
    echo 'fail hex=', var_export($hex, true), ' oct=', var_export($oct, true), ' plain=', var_export($plain, true), "\n";
    exit(1);
}
echo "ok\n";
