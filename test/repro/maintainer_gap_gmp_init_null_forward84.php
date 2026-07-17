<?php
/** Repro for #20210 — gmp_init(null) TypeError under PROFILE=8.4. */
try {
    $z = gmp_init(null);
    echo 'COERCED ', var_export(gmp_strval($z), true), "\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
?>
