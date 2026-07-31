<?php
$f = NumberFormatter::create('en_US', -999);
var_export($f === null);
echo "\n";
echo intl_get_error_code(), ' ', intl_get_error_message(), "\n";
try {
    new NumberFormatter('en_US', -999);
    echo "constructed\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
