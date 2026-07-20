<?php
/**
 * #21234 — header(null) DEP+coerce on PHP_COMPILER_PROFILE=8.4 (php-src ext/standard/head.c).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_header_null_typeerror.php
 * AOT: PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/hnull test/repro/maintainer_gap_header_null_typeerror_aot.php && /tmp/hnull
 */
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        ++$deps;
    }

    return true;
});
try {
    header(null);
    if ($deps < 1) {
        echo "FAIL: missing deprecation\n";
        exit(1);
    }
    echo "ok\n";
} catch (TypeError $e) {
    echo "FAIL: TypeError: {$e->getMessage()}\n";
    exit(1);
}
