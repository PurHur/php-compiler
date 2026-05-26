--TEST--
stdlib arsort() JIT string keys integer values (#2296)
--FILE--
<?php
$data = array('z' => 9, 'a' => 1);
arsort($data);
foreach ($data as $key => $value) {
    echo $key, ':', $value, "\n";
}
--EXPECT--
z:9
a:1
