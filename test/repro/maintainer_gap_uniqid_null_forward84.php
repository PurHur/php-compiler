<?php
// Repro #21280 / was #20138 — uniqid(null) soft-null under PHP_COMPILER_PROFILE=8.4
try {
    var_export(uniqid(null));
    echo " COERCE\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
