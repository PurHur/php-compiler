--TEST--
AOT: sprintf/printf packed argv indexing (#23871)
--FILE--
<?php
$x = 2;
echo sprintf("%d-%d", $x + 1, $x + 2), "\n";
echo sprintf("%d-%d", 3, 4), "\n";
echo sprintf("%d", $x + 1), "\n";
printf("%s/%s\n", "a1", "b2");
printf("%s/%s\n", "a" . "1", "b" . "2");
--EXPECT--
3-4
3-4
3
a1/b2
a1/b2
