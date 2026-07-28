<?php
// #24259 — AOT print_r(bool) segfaulted; thin scalar bridge matches zend_print_zval_r.
// @differential-repeat: 5 heap corruption / signal must not hide behind a single green run
echo "f=";
print_r(false);
echo "|t=";
print_r(true);
echo "|";
$a = [false, true];
echo "a0=";
print_r($a[0]);
echo "|a1=";
print_r($a[1]);
echo "|done\n";
