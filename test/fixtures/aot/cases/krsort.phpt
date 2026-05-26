--TEST--
AOT: krsort() string-key hashtable
--FILE--
<?php
$data = array('z' => 1, 'm' => 2, 'a' => 3);
krsort($data);
echo implode(',', array_keys($data)), "\n";
--EXPECT--
z,m,a
