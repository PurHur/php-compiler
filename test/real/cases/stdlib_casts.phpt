--TEST--
Integration: floatval, boolval, gettype, and strval for null
--FILE--
<?php
echo floatval(intval(2.9)), "\n";
echo boolval(floatval(1)) ? 'y' : 'n', "\n";
echo gettype(null), "\n";
echo is_bool(boolval(true)) ? 'y' : 'n', "\n";
echo strval(null), "\n";
echo strlen(strval(pow(2, 2))), "\n";
--EXPECT--
2
y
NULL
y

1
