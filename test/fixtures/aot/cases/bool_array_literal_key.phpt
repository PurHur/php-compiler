--TEST--
AOT: bool keys in array literals coerce like Zend (true→1), no trunc i1→i64
--FILE--
<?php
$a = [true => 7];
echo $a[true], "\n";
$b = [true => [true => 9]];
echo $b[true][true], "\n";
var_dump(isset($b[1][1]));
--EXPECT--
7
9
bool(true)
