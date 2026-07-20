<?php
/**
 * Issue #20353 / #21492: getimagesizefromstring(null) soft-null under PHP_COMPILER_PROFILE=8.4
 * (Zend 8.4 DEP+notice+false; #21492 reverts over-strict TypeError).
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20353_getimagesizefromstring_null_forward84.php
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no || E_NOTICE === $no || E_WARNING === $no;
});
try {
    var_export(getimagesizefromstring(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
