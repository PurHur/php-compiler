--TEST--
stdlib mb_strwidth() / mb_strimwidth() JIT (issue #3495)
--FILE--
<?php
echo mb_strwidth("あa", 'UTF-8'), "\n";
echo mb_strimwidth("あいう", 0, 4, '', 'UTF-8'), "|", "\n";
echo mb_strimwidth('hello', 0, 3, '..'), "|", "\n";
--EXPECT--
3
あい|
h..|
