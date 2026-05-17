--TEST--
stdlib is_infinite() for integers and floats
--FILE--
<?php
echo is_infinite(7) ? 'y' : 'n', "\n";
echo is_infinite(INF) ? 'y' : 'n', "\n";
echo is_infinite(-INF) ? 'y' : 'n', "\n";
echo is_infinite(NAN) ? 'y' : 'n', "\n";
--EXPECT--
n
y
y
n
