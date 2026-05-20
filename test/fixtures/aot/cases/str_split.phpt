--TEST--
AOT: str_split() chunks strings into indexed list
--FILE--
<?php
$p = str_split('abcd', 2);
echo count($p), "\n";
echo $p[0], '|', $p[1], "\n";
echo count(str_split('')), "\n";
--EXPECT--
2
ab|cd
0
