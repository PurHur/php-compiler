--TEST--
AOT: array literal numeric-string / int key collision (#4151)
--FILE--
<?php
$a = ['123' => 1, 123 => 2];
echo $a[123], "\n";
$b = [123 => 1, '123' => 2];
echo $b[123], "\n";
?>
--EXPECT--
2
2
