--TEST--
stdlib hexdec() large hex overflow var_dump matches Zend (#5412, ext/standard/math.c)
--FILE--
<?php
var_dump(hexdec('FFFFFFFFFFFFFFFF'));
var_dump(hexdec('8000000000000000'));
var_dump(bindec(str_repeat('1', 65)));
--EXPECT--
float(1.8446744073709552E+19)
float(9.223372036854776E+18)
float(3.6893488147419103E+19)
