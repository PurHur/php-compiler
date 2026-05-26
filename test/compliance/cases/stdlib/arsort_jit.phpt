--TEST--
stdlib arsort() JIT string-key hashtable (#2296)
--FILE--
<?php
$data = array('b' => 'zebra', 'a' => 'apple', 'c' => 'mango');
arsort($data);
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
b:zebra
c:mango
a:apple
