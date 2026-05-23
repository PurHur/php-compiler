--TEST--
stdlib str_rot13()
--FILE--
<?php
echo str_rot13(''), "\n";
echo str_rot13('abc'), "\n";
echo str_rot13('nop'), "\n";
echo str_rot13('ABC'), "\n";
echo str_rot13('NOP'), "\n";
echo str_rot13('Hello, World!'), "\n";
echo str_rot13(str_rot13('double')), "\n";
--EXPECT--

nop
abc
NOP
ABC
Uryyb, Jbeyq!
double
