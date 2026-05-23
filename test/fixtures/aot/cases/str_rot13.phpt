--TEST--
AOT: str_rot13() ASCII ROT13
--FILE--
<?php
echo str_rot13('abc'), "\n";
echo str_rot13('Hello'), "\n";
--EXPECT--
nop
Uryyb
