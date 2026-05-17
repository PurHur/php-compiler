--TEST--
stdlib pow() with integer base and exponent
--FILE--
<?php
echo intval(pow(3, 2)), "\n";
echo intval(pow(2, 4)), "\n";
--EXPECT--
9
16
