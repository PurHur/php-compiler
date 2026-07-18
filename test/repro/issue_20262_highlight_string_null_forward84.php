<?php
// #20262 — highlight_string(null) TypeError on 8.4 forward profile
// (php-src ext/standard/basic_functions.c Z_PARAM_STR(str))
// VM: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20262_highlight_string_null_forward84.php
// AOT bare (exit 255): see test/fixtures/aot/cases/highlight_string_null_forward84.phpt
try {
    highlight_string(null, true);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
