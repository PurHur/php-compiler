--TEST--
list() destructuring from string leaves targets unset (Zend VM parity, #4308)
--FILE--
<?php
[$a] = 'ab';
echo $a === null ? 'null' : $a;
echo "\n";
[$b, $c] = 'xy';
echo 'b=', var_export($b, true), ' c=', var_export($c, true), "\n";
--EXPECT--
null
b=NULL c=NULL
