--TEST--
stdlib is_numeric() for supported types
--FILE--
<?php
echo is_numeric(42) ? 'y' : 'n', "\n";
echo is_numeric(3.14) ? 'y' : 'n', "\n";
echo is_numeric('123') ? 'y' : 'n', "\n";
echo is_numeric('12.5') ? 'y' : 'n', "\n";
echo is_numeric('-7') ? 'y' : 'n', "\n";
echo is_numeric('abc') ? 'y' : 'n', "\n";
echo is_numeric('') ? 'y' : 'n', "\n";
echo is_numeric(true) ? 'y' : 'n', "\n";
echo is_numeric(null) ? 'y' : 'n', "\n";
--EXPECT--
y
y
y
y
y
n
n
n
n
