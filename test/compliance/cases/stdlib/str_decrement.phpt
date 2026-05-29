--TEST--
stdlib str_decrement() (#3102)
--FILE--
<?php
echo str_decrement('10'), "\n";
echo str_decrement('B'), "\n";
echo str_decrement('aa'), "\n";
echo str_decrement('Ba'), "\n";
echo str_decrement('B0'), "\n";
echo str_decrement('5e7'), "\n";
--EXPECT--
9
A
z
Az
A9
5e6
