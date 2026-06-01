--TEST--
AOT: array_replace_recursive() nested merge (#3166)
--FILE--
<?php
$a = array('x' => array('a' => 1));
$b = array('x' => array('b' => 2));
$r = array_replace_recursive($a, $b);
echo $r['x']['a'], "\n";
echo $r['x']['b'], "\n";
$p = array(1, 2, 3);
$q = array(0 => 10, 2 => array('z' => 9));
$s = array_replace_recursive($p, $q);
echo count($s), "\n";
echo $s[0], "\n";
echo $s[1], "\n";
echo $s[2]['z'], "\n";
--EXPECT--
1
2
3
10
2
9
