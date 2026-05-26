--TEST--
stdlib krsort() JIT string-key hashtable (issue #2282)
--FILE--
<?php
$data = array('z' => 1, 'm' => 2, 'a' => 3);
krsort($data);
echo implode(',', array_keys($data)), "\n";
--EXPECT--
z,m,a
