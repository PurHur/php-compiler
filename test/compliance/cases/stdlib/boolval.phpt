--TEST--
stdlib boolval() for scalar values
--FILE--
<?php
echo boolval(0) ? 'y' : 'n', "\n";
echo boolval(1) ? 'y' : 'n', "\n";
echo boolval(0.0) ? 'y' : 'n', "\n";
echo boolval(2.5) ? 'y' : 'n', "\n";
echo boolval(true) ? 'y' : 'n', "\n";
echo boolval(false) ? 'y' : 'n', "\n";
echo boolval('') ? 'y' : 'n', "\n";
echo boolval('0') ? 'y' : 'n', "\n";
echo boolval('x') ? 'y' : 'n', "\n";
echo boolval(null) ? 'y' : 'n', "\n";
--EXPECT--
n
y
n
y
y
n
n
n
y
n
