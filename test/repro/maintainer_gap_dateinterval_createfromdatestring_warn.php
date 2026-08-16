<?php
/** Maintainer gap: DateInterval::createFromDateString bad token Warning text (php-src-strict). */
@DateInterval::createFromDateString('@@@');
// Capture warning via error handler for stable comparison
$msg = null;
set_error_handler(function ($errno, $errstr) use (&$msg) {
    $msg = $errstr;
    return true;
});
$r = DateInterval::createFromDateString('@@@');
restore_error_handler();
echo 'return=';
var_export($r);
echo "\nwarning=";
echo $msg === null ? 'NULL' : $msg;
echo "\n";
