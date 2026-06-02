--TEST--
language: extract() JIT with float/double array values (#4094)
--FILE--
<?php
$t = array('x' => 1.5);
extract($t);
echo json_encode($x);
--EXPECT--
1.5
