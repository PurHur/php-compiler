--TEST--
AOT str_increment() (#3102)
--FILE--
<?php
echo str_increment('9'), "\n";
echo str_increment('Az'), "\n";
echo str_increment('z'), "\n";
--EXPECT--
10
Ba
aa
