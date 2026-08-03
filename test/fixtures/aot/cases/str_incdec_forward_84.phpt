--TEST--
AOT str_increment()/str_decrement() PROFILE=8.4 (#27345)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo str_increment('a'), "\n";
echo str_decrement('b'), "\n";
echo str_increment('9'), "\n";
echo str_increment('Az'), "\n";
echo str_increment('z'), "\n";
echo str_decrement('10'), "\n";
echo str_decrement('aa'), "\n";
--EXPECT--
b
a
10
Ba
aa
9
z
