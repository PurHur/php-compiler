<?php
// #18852 — json_decode(null) TypeError on 8.4 forward profile (ext/json/php_json.c).
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_json_decode_null_84.php

try {
    json_decode(null);
    echo "uncaught\n";
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
    exit(0);
}
