<?php
$overflow = filter_var(PHP_INT_MAX . '0', FILTER_VALIDATE_INT);
if (false !== $overflow) {
    echo 'fail: overflow int validated as ' . var_export($overflow, true) . "\n";
    exit(1);
}
$leadingZero = filter_var('0123', FILTER_VALIDATE_INT);
if (false !== $leadingZero) {
    echo 'fail: leading zero accepted as ' . var_export($leadingZero, true) . "\n";
    exit(1);
}
echo "ok\n";
