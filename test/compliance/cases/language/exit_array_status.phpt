--TEST--
Language: exit()/die() with array status — warning + Array output, exit 0 (#5441, basic_functions.c)
--FILE--
<?php
exit([]);
--EXPECT--
Array
--EXPECT_EXIT--
0
