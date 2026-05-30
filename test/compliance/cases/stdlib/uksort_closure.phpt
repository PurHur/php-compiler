--TEST--
stdlib uksort() closure comparator on keys (issue #3143, #3086)
--FILE--
<?php
$data = ['b' => 2, 'a' => 1];
uksort($data, fn($x, $y) => strcmp($x, $y));
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
a:1
b:2
