<?php
// #18796 — timezone_open(null) TypeError on 8.4 forward profile.
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_timezone_open_null_typeerror.php

try {
    timezone_open(null);
    echo "uncaught\n";
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
    exit(0);
}
