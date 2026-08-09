<?php
error_reporting(E_ALL);
set_error_handler(function ($errno, $errstr) {
    echo "ERR:$errno:$errstr\n";
    return true;
});
$dt = date_create('2020-01-01');
try {
    $r = date_modify($dt, null);
    echo 'ret=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
