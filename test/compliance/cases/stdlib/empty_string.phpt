--TEST--
stdlib empty() for empty string
--FILE--
<?php
echo empty('') ? 'y' : 'n', "\n";
echo empty('0') ? 'y' : 'n', "\n";
echo empty('x') ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
