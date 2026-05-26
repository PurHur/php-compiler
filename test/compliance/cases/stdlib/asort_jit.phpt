--TEST--
stdlib asort() JIT string-key hashtable (#2290)
--FILE--
<?php
$data = array('b' => 'zebra', 'a' => 'apple', 'c' => 'mango');
asort($data);
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
a:apple
c:mango
b:zebra
