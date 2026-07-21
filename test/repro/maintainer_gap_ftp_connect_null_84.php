<?php
/** Repro for #21757 — ftp_connect/ftp_ssl_connect(null) DEP+false under PROFILE=8.4. */
foreach (['ftp_connect', 'ftp_ssl_connect'] as $fn) {
    try {
        $r = @$fn(null);
        echo "$fn COERCED ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$fn ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
