--TEST--
AOT: string compare builtins — enum case operands TypeError (#5733)
--FILE--
<?php
enum S: string { case X = 'a'; }
strncmp(S::X, 'b', 1);
--EXPECT--
--EXPECT_EXIT--
134
