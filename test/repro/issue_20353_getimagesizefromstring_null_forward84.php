<?php
/**
 * Issue #20353: getimagesizefromstring(null) TypeError under PHP_COMPILER_PROFILE=8.4.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20353_getimagesizefromstring_null_forward84.php
 */
try {
    var_export(getimagesizefromstring(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
