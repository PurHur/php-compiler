<?php
// Mixed keys — numeric-string keys sort with int keys in Zend (#4461).
$a = [
    2 => 'two',
    '10' => 'ten_str_key',
    1 => 'one',
    'a' => 'A',
];
$ak = $a;
ksort($ak);
var_export(array_keys($ak));
echo "\n";

// Mixed values
$b = ['x' => 10, 'y' => '2', 'z' => 3];
$bv = $b;
asort($bv);
var_export($bv);
echo "\n";
