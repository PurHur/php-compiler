--TEST--
stdlib get_debug_type() — PHP 8.0 precise type names (#3080)
--FILE--
<?php
class Box {}
echo get_debug_type(null), "\n";
echo get_debug_type(1), "\n";
echo get_debug_type(1.5), "\n";
echo get_debug_type(true), "\n";
echo get_debug_type('x'), "\n";
echo get_debug_type([]), "\n";
echo get_debug_type(new Box()), "\n";
--EXPECT--
null
int
float
bool
string
array
Box
