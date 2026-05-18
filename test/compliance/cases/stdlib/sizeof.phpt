--TEST--
stdlib sizeof() alias of count()
--FILE--
<?php
$a = array(1, 2);
echo sizeof($a), "\n";
echo sizeof(array()), "\n";
--EXPECT--
2
0
