--TEST--
AOT: ltrim() and rtrim() with default whitespace mask
--FILE--
<?php
echo ltrim('  hello'), "\n";
echo rtrim("world\n"), "\n";
echo ltrim("\tfoo"), "\n";
echo rtrim("bar  "), "\n";
--EXPECT--
hello
world
foo
bar
--EXPECT_EXIT--
0
