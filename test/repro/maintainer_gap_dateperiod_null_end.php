<?php
// DatePeriod(null end): soft-null E_DEPRECATED then Exception (php-src php_date.c).
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});
try {
    $p = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
