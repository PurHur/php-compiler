--TEST--
concat with multiple ?? operands in one expression (#17375)
--FILE--
<?php
$fromFile = [0 => 1, 1 => 1];
echo ($fromFile[0] ?? 'x') . ($fromFile[1] ?? 'y'), "\n";
echo ($fromFile[0] ?? 'x') . 'x' . ($fromFile[1] ?? 'x'), "\n";
?>
--EXPECT--
11
1x1
