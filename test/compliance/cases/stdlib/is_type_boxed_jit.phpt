--TEST--
stdlib is_* on boxed values
--FILE--
<?php
$a = 42;
$b = 1.5;
$c = true;
$d = 'hi';
$e = null;
echo is_int($a) ? 'y' : 'n', "\n";
echo is_float($b) ? 'y' : 'n', "\n";
echo is_bool($c) ? 'y' : 'n', "\n";
echo is_string($d) ? 'y' : 'n', "\n";
echo is_null($e) ? 'y' : 'n', "\n";
--EXPECT--
y
y
y
y
y
