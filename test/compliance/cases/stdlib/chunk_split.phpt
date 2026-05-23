--TEST--
stdlib chunk_split()
--FILE--
<?php
echo chunk_split('1234567890', 3, '-'), "\n";
echo chunk_split('abcde', 2, '|'), "\n";
echo chunk_split('x', 1, '.'), "\n";
echo chunk_split('', 3), "\n";
--EXPECT--
123-456-789-0-
ab|cd|e|
x.
