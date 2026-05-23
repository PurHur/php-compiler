--TEST--
AOT array_flip() for string and int values
--FILE--
<?php
$a = array('a' => 1, 'b' => 2);
$f = array_flip($a);
echo $f[1], "\n";
echo $f[2], "\n";
--EXPECT--
a
b
