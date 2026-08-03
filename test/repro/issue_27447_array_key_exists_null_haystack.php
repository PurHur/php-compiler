<?php
/** Repro #27447 — AOT array_key_exists(null haystack) TypeError (php-src-strict). */
try {
    var_export(array_key_exists('a', null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
