<?php
declare(strict_types=1);

$a = [];
$a['b'] = 'zebra';
$a['a'] = 'apple';
$a['c'] = 'mango';
array_uasort($a, 'strcmp');
foreach ($a as $key => $value) {
    echo $key, ':', $value, "\n";
}
