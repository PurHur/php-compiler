--TEST--
stdlib uasort() preserves keys while sorting values (issue #1211)
--FILE--
<?php
$data = ['b' => 'zebra', 'a' => 'apple', 'c' => 'mango'];
uasort($data, 'strcmp');
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
a:apple
c:mango
b:zebra
