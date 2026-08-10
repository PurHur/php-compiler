<?php
// #29348 — range(null, …) $start/$end: Zend soft-null DEP then coerce (not silent)
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    fwrite(STDERR, "WARN[$errno]: $errstr\n");

    return true;
});
var_export(range(null, 3));
echo "\n";
