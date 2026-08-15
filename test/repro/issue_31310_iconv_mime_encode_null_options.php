<?php

// #31310 — iconv_mime_encode(..., null) $options must TypeError (php-src Z_PARAM_ARRAY).
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    var_export(iconv_mime_encode('s', 'b', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo iconv_mime_encode('s', 'b'), "\n";
