<?php
/** Maintainer repro for #6049 — ini_parse_quantity() parity. */
var_export(ini_parse_quantity('1G'));
echo "\n";
try {
    ini_parse_quantity('not-a-quantity');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
