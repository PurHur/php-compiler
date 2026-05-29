--TEST--
stdlib str_increment() (#3102)
--FILE--
<?php
echo str_increment('9'), "\n";
echo str_increment('A'), "\n";
echo str_increment('z'), "\n";
echo str_increment('Z'), "\n";
echo str_increment('Az'), "\n";
echo str_increment('A9'), "\n";
echo str_increment('5e6'), "\n";
--EXPECT--
10
B
aa
AA
Ba
B0
5e7
