--TEST--
Pre/post increment (++/--) expression values (JIT)
--FILE--
<?php
$i = 0;
echo ++$i, $i, $i++;
echo "\n";
$j = 5;
echo $j--, $j;
echo "\n";
--EXPECT--
112
54
