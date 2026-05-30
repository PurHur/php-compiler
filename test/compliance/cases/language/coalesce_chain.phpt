--TEST--
Language: chained null coalesce (??) — issue #3798
--FILE--
<?php
$a = null;
$b = null;
echo $a ?? $b ?? 'z', "\n";

$c = 0;
echo $c ?? 'zero', "\n";
echo null ?? 'n', "\n";
--EXPECT--
z
0
n
