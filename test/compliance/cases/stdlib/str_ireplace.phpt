--TEST--
stdlib str_ireplace()
--FILE--
<?php
echo str_ireplace('O', '0', 'fOo'), "\n";
echo str_ireplace('AB', 'X', 'cab AbC'), "\n";
echo str_ireplace('z', '', 'no match'), "\n";
--EXPECT--
f00
cX XC
no match
