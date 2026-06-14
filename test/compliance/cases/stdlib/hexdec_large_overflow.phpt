--TEST--
stdlib hexdec() large hex string float matches Zend var_dump (#5412)
--FILE--
<?php
var_dump(hexdec('FFFFFFFFFFFFFFFF'));
--EXPECT--
float(1.8446744073709552E+19)
