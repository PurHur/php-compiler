<?php
/** Maintainer gap: DateTimeImmutable::modify('@@@') Warning detail (php-src-strict). */
$msg = null;
set_error_handler(function ($errno, $errstr) use (&$msg) {
    $msg = $errstr;
    return true;
});
$d = new DateTimeImmutable('2020-01-01');
$r = $d->modify('@@@');
restore_error_handler();
echo 'ret=';
var_export($r);
echo "\nwarning=";
echo $msg === null ? 'NULL' : $msg;
echo "\n";
