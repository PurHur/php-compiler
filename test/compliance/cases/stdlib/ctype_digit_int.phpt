--TEST--
stdlib ctype_digit() integer operand parity (issue #7253)
--FILE--
<?php
echo (int) ctype_digit('123'), "\n";
echo (int) ctype_digit(57), "\n";
echo (int) ctype_digit(256), "\n";
echo (int) ctype_digit(-1), "\n";
?>
--EXPECT--
1
1
1
0
