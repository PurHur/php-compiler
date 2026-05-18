--TEST--
Integration: is_scalar, is_numeric, lcfirst, and ucfirst
--FILE--
<?php
echo is_scalar(0) && is_numeric(0) ? 'y' : 'n', "\n";
echo is_scalar(null) || is_numeric(null) ? 'y' : 'n', "\n";
echo is_numeric('9.5') && !is_numeric('9.5x') ? 'y' : 'n', "\n";
echo lcfirst(ucfirst('php')), "\n";
echo strcmp(ucfirst('abc'), 'Abc'), "\n";
--EXPECT--
y
n
y
php
0
