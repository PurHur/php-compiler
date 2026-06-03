--TEST--
list() destructuring from null/false/int leaves targets unset (Zend VM parity, #4325)
--FILE--
<?php
[$a, $b] = null;
echo "a=", var_export($a, true), " b=", var_export($b, true), "\n";
list($x) = false;
echo "x=", var_export($x, true), "\n";
[$y] = 0;
echo "y=", var_export($y, true), "\n";
--EXPECT--
a=NULL b=NULL
x=NULL
y=NULL
