--TEST--
AOT: str_split() named string:/length: arguments (#23206)
--FILE--
<?php
echo implode(',', str_split(string: 'abcd', length: 2)), "\n";
echo implode(',', str_split(string: 'xy', length: 1)), "\n";
--EXPECT--
ab,cd
x,y
