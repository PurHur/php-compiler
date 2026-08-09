<?php
error_reporting(E_ALL);
set_error_handler(function ($errno, $errstr) {
    echo "ERR:$errno:$errstr\n";
    return true;
});
try {
    $r = DateInterval::createFromDateString('');
    echo 'method:ret=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'method:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $r = date_interval_create_from_date_string('');
    echo 'proc:ret=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'proc:', get_class($e), ':', $e->getMessage(), "\n";
}
