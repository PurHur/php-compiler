<?php
/**
 * getdate/strtotime ArgumentCountError wording (#30714).
 * php-src: ext/date/php_date.c
 */
try {
    getdate(1, 2);
    echo "getdate:OK\n";
} catch (ArgumentCountError $e) {
    echo 'getdate:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'getdate:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    strtotime('now', null, 1);
    echo "strtotime_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'strtotime_hi:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'strtotime_hi:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    strtotime();
    echo "strtotime_lo:OK\n";
} catch (ArgumentCountError $e) {
    echo 'strtotime_lo:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'strtotime_lo:', get_class($e), ':', $e->getMessage(), "\n";
}

$g = getdate(0);
echo 'ok_getdate:', isset($g['year']) ? '1' : '0', "\n";
$t = strtotime('1970-01-01 00:00:00 UTC');
echo 'ok_strtotime:', (false !== $t) ? '1' : '0', "\n";
