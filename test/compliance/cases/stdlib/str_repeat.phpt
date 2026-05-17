--TEST--
stdlib str_repeat()
--FILE--
<?php
echo str_repeat('ab', 3), "\n";
echo str_repeat('x', 0), "\n";
--EXPECT--
ababab

