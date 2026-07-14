<?php
// #18838 — ord(null) must TypeError on 8.4 forward profile.
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_ord_null_coerce.php

try {
    ord(null);
    echo "ord(null)=uncaught\n";
} catch (TypeError $e) {
    echo 'ord(null)=TypeError:', $e->getMessage(), "\n";
}
