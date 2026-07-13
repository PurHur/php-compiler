--TEST--
AOT: uasort() associative array strcmp preserves keys (#5698, ext/standard/array.c php_array_uasort)
--FILE--
<?php
$a = ['b' => 'zebra', 'a' => 'apple', 'c' => 'mango'];
uasort($a, 'strcmp');
foreach ($a as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
a:apple
c:mango
b:zebra
