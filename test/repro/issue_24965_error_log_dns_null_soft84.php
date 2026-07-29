<?php
/**
 * Repro #24965 (re-#24178) — PROFILE=8.4 soft-null for error_log/gethostbyname/dns_get_record.
 * Zend deprecates and coerces; TypeError only under declare(strict_types=1).
 *
 * Named error handler (not a closure) so AOT can compile (#1379).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24965_error_log_dns_null_soft84.php
 * AOT: PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/issue24965 test/repro/issue_24965_error_log_dns_null_soft84.php && /tmp/issue24965
 */
error_reporting(E_ALL);
function issue_24965_dep_handler(int $n, string $s): bool
{
    if ($n === E_DEPRECATED) {
        echo "DEP\n";

        return true;
    }

    return false;
}
set_error_handler('issue_24965_dep_handler');
try {
    var_export(error_log(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    var_export(gethostbyname(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    var_export(dns_get_record(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
