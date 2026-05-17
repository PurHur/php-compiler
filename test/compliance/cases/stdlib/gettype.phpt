--TEST--
stdlib gettype() for scalar values
--FILE--
<?php
echo gettype(1), "\n";
echo gettype(1.5), "\n";
echo gettype('x'), "\n";
echo gettype(true), "\n";
--EXPECT--
integer
double
string
boolean
