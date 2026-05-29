--TEST--
AOT str_decrement() (#3102)
--FILE--
<?php
echo str_decrement('10'), "\n";
echo str_decrement('Ba'), "\n";
echo str_decrement('aa'), "\n";
--EXPECT--
9
Az
z
