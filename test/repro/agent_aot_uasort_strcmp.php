<?php
declare(strict_types=1);

$a = ['b' => 'zebra', 'a' => 'apple', 'c' => 'mango'];
uasort($a, 'strcmp');
foreach ($a as $key => $value) {
    echo $key, ':', $value, "\n";
}
