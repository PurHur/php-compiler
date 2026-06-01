--TEST--
stdlib strpos/stripos() negative offset (issue #4103)
--FILE--
<?php
echo strpos('abc', 'bc', -1) == false ? "false\n" : "?\n";
echo stripos('abc', 'B', -1) == false ? "false\n" : "?\n";
echo strpos('abcdef', 'de', -4), "\n";
--EXPECT--
false
false
3
