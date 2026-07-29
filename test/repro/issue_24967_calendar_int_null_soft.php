<?php
/**
 * Issue #24967 — gregoriantojd/cal_days_in_month null soft-coerce (ext/calendar/calendar.c).
 *
 * VM: php bin/vm.php test/repro/issue_24967_calendar_int_null_soft.php
 *     PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24967_calendar_int_null_soft.php
 */
error_reporting(E_ALL);
function issue_24967_dep_handler(int $n, string $s): bool
{
    if ($n === E_DEPRECATED) {
        echo "DEP\n";

        return true;
    }

    return false;
}
set_error_handler('issue_24967_dep_handler');
try {
    var_export(gregoriantojd(null, 1, 2000));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(cal_days_in_month(CAL_GREGORIAN, null, 2000));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
