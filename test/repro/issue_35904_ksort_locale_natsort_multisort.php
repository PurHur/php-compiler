<?php
// AOT leftover of #35626 — ksort(SORT_LOCALE_STRING) / natsort / array_multisort (#35904).
// php-src: ext/standard/array.c php_array_ksort / php_natsort / php_array_multisort

$k = ['b' => 2, 'a' => 1, 'c' => 3];
ksort($k, SORT_LOCALE_STRING);
echo 'ksort:';
foreach ($k as $key => $v) {
    echo $key, $v, ';';
}
echo "\n";

$a = ['b' => 'z', 'a' => 'x', 'c' => 'y'];
asort($a, SORT_LOCALE_STRING);
echo 'asort:';
foreach ($a as $key => $v) {
    echo $key, $v, ';';
}
echo "\n";

$n = ['a10', 'a2'];
natsort($n);
echo 'natsort:';
foreach ($n as $key => $v) {
    echo $key, ':', $v, ';';
}
echo "\n";

$x = [3, 1, 2];
$y = ['c', 'a', 'b'];
array_multisort($x, $y);
echo 'multi:', implode(',', $x), '|', implode(',', $y), "\n";
