<?php
/**
 * #19224 — header(null) must TypeError on PHP_COMPILER_PROFILE=8.4 (php-src ext/standard/head.c).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_header_null_typeerror.php
 * AOT: PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/hnull test/repro/maintainer_gap_header_null_typeerror_aot.php
 *      (AOT uses abort-path fixture — try/catch around emitTypeErrorAndAbort is not used)
 */
try {
    header(null);
    echo "FAIL: uncaught\n";
    exit(1);
} catch (TypeError $e) {
    $msg = $e->getMessage();
    if (!str_contains($msg, 'must be of type string, null given')) {
        echo "FAIL: unexpected message: {$msg}\n";
        exit(1);
    }
    echo "ok\n";
}
