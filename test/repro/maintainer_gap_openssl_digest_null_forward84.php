<?php
/** Repro for #20207 — openssl_digest(null) TypeError under PROFILE=8.4. */
try {
    var_export(openssl_digest(null, 'sha256'));
    echo " COERCED\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
