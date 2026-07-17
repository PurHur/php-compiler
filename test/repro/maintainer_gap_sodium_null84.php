<?php
/** Repro for #20196 — sodium_bin2hex/hex2bin(null) TypeError under PROFILE=8.4. */
foreach (['sodium_bin2hex', 'sodium_hex2bin'] as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ' coerced:', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $fn, ' ', get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}
