<?php
/** Repro for #20484 — ftp_connect/ftp_ssl_connect(null) TypeError under PROFILE=8.4. */
foreach (['ftp_connect', 'ftp_ssl_connect'] as $fn) {
    try {
        $r = $fn(null);
        echo "$fn COERCED ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$fn ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
