--TEST--
Integration: floatval, boolval, and gettype for null
--FILE--
<?php
echo floatval(intval(2.9)), "\n";
echo boolval(floatval(1)) ? 'y' : 'n', "\n";
echo gettype(null), "\n";
echo is_bool(boolval(true)) ? 'y' : 'n', "\n";
--EXPECT--
2
y
NULL
y
