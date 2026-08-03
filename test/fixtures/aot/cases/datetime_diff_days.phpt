--TEST--
AOT: DateTime::diff()->days between 2024-01-01 and 2024-01-10 (#27309)
--FILE--
<?php
$a = new DateTime('2024-01-01');
$b = new DateTime('2024-01-10');
echo $a->diff($b)->days, "\n";
--EXPECT--
9
