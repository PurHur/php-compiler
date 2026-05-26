--TEST--
AOT: rsort() packed list descending (#2300)
--FILE--
<?php
$b = array(3, 1, 2);
rsort($b);
echo count($b), ':', $b[0], '|', $b[2], "\n";
--EXPECT--
3:3|1
