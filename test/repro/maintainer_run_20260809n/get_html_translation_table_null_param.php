<?php

/**
 * #29395 — get_html_translation_table(null) soft-null DEP cites parameter #1 ($table).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_run_20260809n/get_html_translation_table_null_param.php
 */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$table = get_html_translation_table(null);
echo isset($table['&']) ? "ok\n" : "missing &\n";
