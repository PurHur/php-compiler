<?php
/**
 * Issue #24966 — gethostbynamel(null) soft-null DEP+false under PROFILE=8.4
 * (ext/standard/dns.c; sibling of #24965 gethostbyname).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24966_gethostbynamel_null_soft84.php
 */
error_reporting(E_ALL);
function issue_24966_dep_handler(int $n, string $s): bool
{
    if ($n === E_DEPRECATED) {
        echo "DEP\n";

        return true;
    }

    return false;
}
set_error_handler('issue_24966_dep_handler');
try {
    var_export(gethostbynamel(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
