<?php
$a = [];
$a[] = &$a;
$r = count($a, COUNT_RECURSIVE);
echo "count=$r\n";
echo error_get_last()['message'] ?? 'no-warning', "\n";

$b = [];
$c = [];
$b[] = &$c;
$c[] = &$b;
$r2 = count($b, COUNT_RECURSIVE);
echo "cycle2=$r2\n";
