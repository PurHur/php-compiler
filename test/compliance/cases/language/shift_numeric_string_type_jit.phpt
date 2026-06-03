--TEST--
Language: << and >> with numeric-string operands return integer type (JIT, #4999)
--FILE--
<?php
$x = '2' << 1;
echo gettype($x), ':', $x, "\n";
$y = '8' >> 1;
echo gettype($y), ':', $y, "\n";
--EXPECT--
integer:4
integer:4
