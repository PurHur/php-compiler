--TEST--
stdlib empty() for scalar values
--FILE--
<?php
echo empty(0) ? 'y' : 'n', "\n";
echo empty(1) ? 'y' : 'n', "\n";
echo empty(0.0) ? 'y' : 'n', "\n";
echo empty(2.5) ? 'y' : 'n', "\n";
echo empty(true) ? 'y' : 'n', "\n";
echo empty(false) ? 'y' : 'n', "\n";
echo empty('') ? 'y' : 'n', "\n";
echo empty('0') ? 'y' : 'n', "\n";
echo empty('x') ? 'y' : 'n', "\n";
echo empty(null) ? 'y' : 'n', "\n";
--EXPECT--
y
n
y
n
n
y
y
y
n
y
