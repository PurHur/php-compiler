<?php
// Guard #20352 — simplexml_load_string/file(null) TypeError under PROFILE=8.4
// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_simplexml_load_null_typeerror_84.php
foreach (['simplexml_load_string', 'simplexml_load_file'] as $fn) {
    try {
        $fn(null);
        echo "fail {$fn}\n";
    } catch (TypeError $e) {
        echo "ok {$fn}: ", $e->getMessage(), "\n";
    }
}
