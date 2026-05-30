--TEST--
Logical xor operator (Zend zend_operators.c parity, #2313)
--FILE--
<?php
echo (0 xor 0) ? '1' : '0';
echo (0 xor 1) ? '1' : '0';
echo (1 xor 0) ? '1' : '0';
echo (1 xor 1) ? '1' : '0';
echo (1 xor 2) ? '1' : '0';
echo ('' xor 'x') ? '1' : '0';
echo (0 xor '') ? '1' : '0';
--EXPECT--
0110010
