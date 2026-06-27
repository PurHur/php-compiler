--TEST--
Language: die(message:) named parameter prints message and exits 0 (#12414, basic_functions.c)
--FILE--
<?php
die(message: 'bye');
--EXPECT--
bye
--EXPECT_EXIT--
0
