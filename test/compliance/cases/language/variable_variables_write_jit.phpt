--TEST--
Variable variables write ($$name = value) (JIT)
--FILE--
<?php
$a = 'x';
$x = 1;
$$a = 99;
echo $x, "\n";
--EXPECT--
99
