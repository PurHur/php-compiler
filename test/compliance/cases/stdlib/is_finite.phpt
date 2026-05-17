--TEST--
stdlib is_finite() for integers and floats
--FILE--
<?php
echo is_finite(0) ? 'y' : 'n', "\n";
echo is_finite(2.5) ? 'y' : 'n', "\n";
echo is_finite(INF) ? 'y' : 'n', "\n";
echo is_finite(NAN) ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
n
