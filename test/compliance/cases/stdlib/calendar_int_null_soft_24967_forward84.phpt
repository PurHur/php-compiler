--TEST--
stdlib gregoriantojd/cal_days_in_month null soft-coerce PROFILE=8.4 (#24967, ext/calendar/calendar.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
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
?>
--EXPECT--
DEP
0
DEP
ValueError: Invalid date
