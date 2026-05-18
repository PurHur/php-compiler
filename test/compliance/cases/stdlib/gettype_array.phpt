--TEST--
stdlib gettype() for arrays
--FILE--
<?php
$a = array(1, 2);
echo gettype($a), "\n";
echo gettype(array()), "\n";
--EXPECT--
array
array
