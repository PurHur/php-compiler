<?php
// Repro #20138 — uniqid(null) TypeError under PHP_COMPILER_PROFILE=8.4 (php-src ext/standard/uniqid.c)
// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_uniqid_null_forward84.php
try {
    var_export(uniqid(null));
    echo " COERCE\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
