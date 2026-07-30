<?php
/** Repro #25205 — illegal IntlDateFormatter styles → null / IntlException. */
$f = IntlDateFormatter::create('en_US', -999, -999);
var_export($f === null);
echo "\n";
echo intl_get_error_code(), ' ', intl_get_error_message(), "\n";

$f2 = datefmt_create('en_US', 4, IntlDateFormatter::NONE);
var_export($f2 === null);
echo "\n";
echo intl_get_error_message(), "\n";

$f3 = datefmt_create('en_US', IntlDateFormatter::NONE, 4);
var_export($f3 === null);
echo "\n";
echo intl_get_error_message(), "\n";

try {
    new IntlDateFormatter('en_US', -999, -999);
    echo "ctor: no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
