<?php
// Repro #33620 — AOT asort/arsort on packed lists must preserve keys (php-src php_array_asort).
$a = ['b', 'a'];
asort($a);
foreach ($a as $k => $v) {
    echo $k, $v;
}
echo "\n";

$b = ['a', 'b'];
arsort($b);
foreach ($b as $k => $v) {
    echo $k, $v;
}
echo "\n";

$c = ['x' => 'b', 'y' => 'a'];
asort($c);
foreach ($c as $k => $v) {
    echo $k, $v;
}
echo "\n";
