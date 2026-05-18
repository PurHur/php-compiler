--TEST--
AOT: str_repeat()
--FILE--
<?php
echo str_repeat('ab', 3), "\n";
--EXPECT--
ababab
