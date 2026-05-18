--TEST--
stdlib str_replace()
--FILE--
<?php
echo str_replace('o', '0', 'foo'), "\n";
echo str_replace('ab', 'X', 'cab abc'), "\n";
echo str_replace('z', '', 'no match'), "\n";
--EXPECT--
f00
cX Xc
no match
