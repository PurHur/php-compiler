--TEST--
AOT: ksort() packed list (string keys: VM)
--FILE--
<?php
$b = array(3, 1, 2);
ksort($b);
echo count($b), ':', $b[0], '|', $b[2], "\n";
--EXPECT--
3:3|2
