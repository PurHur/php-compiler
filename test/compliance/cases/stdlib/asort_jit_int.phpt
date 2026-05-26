--TEST--
stdlib asort() JIT string keys integer values (#2290)
--FILE--
<?php
$data = array('z' => 9, 'a' => 1);
asort($data);
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
a:1
z:9
