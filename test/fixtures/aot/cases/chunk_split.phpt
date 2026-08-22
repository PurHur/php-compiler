--TEST--
AOT: chunk_split()
--FILE--
<?php
echo chunk_split('abcd', 2, ':'), "\n";
echo chunk_split('abcdef', 2, ':'), "\n";
echo chunk_split('hi', 1, '-'), "\n";
--EXPECT--
ab:cd:
ab:cd:ef:
h-i-
