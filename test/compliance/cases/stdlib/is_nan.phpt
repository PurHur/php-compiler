--TEST--
stdlib is_nan() for integers and floats
--FILE--
<?php
echo is_nan(0) ? 'y' : 'n', "\n";
echo is_nan(1.5) ? 'y' : 'n', "\n";
echo is_nan(NAN) ? 'y' : 'n', "\n";
--EXPECT--
n
n
y
