<?php
error_reporting(E_ALL);
set_error_handler(function ($errno, $errstr) {
    echo "ERR:$errno:$errstr\n";
    return true;
});
foreach ([
    ['DateTime', fn() => DateTime::createFromFormat('Y', null)],
    ['DateTimeImmutable', fn() => DateTimeImmutable::createFromFormat('Y-m-d', null)],
] as [$label, $fn]) {
    try {
        $r = $fn();
        echo $label, ':ret=', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $label, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
