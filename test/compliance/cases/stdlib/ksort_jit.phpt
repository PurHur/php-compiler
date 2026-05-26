--TEST--
stdlib ksort() JIT string-key hashtable (issue #2271)
--FILE--
<?php
$data = ['b' => 'zebra', 'a' => 'apple', 'c' => 'mango'];
ksort($data);
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
a:apple
b:zebra
c:mango
