--TEST--
AOT: mb_strwidth() / mb_strimwidth() display width (#3495)
--FILE--
<?php
echo mb_strwidth("あa", 'UTF-8'), "\n";
echo mb_strimwidth('hello', 0, 3, '..'), "|", "\n";
--EXPECT--
3
h..|
