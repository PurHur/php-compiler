--TEST--
stdlib str_split()
--FILE--
<?php
$p = str_split('');
echo count($p), "\n";
$p = str_split('ab');
echo count($p), "\n";
echo $p[0], $p[1], "\n";
$p = str_split('abcd', 2);
echo count($p), "\n";
echo $p[0], '|', $p[1], "\n";
--EXPECT--
0
2
ab
2
ab|cd
