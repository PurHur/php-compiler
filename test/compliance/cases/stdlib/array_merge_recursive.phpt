--TEST--
stdlib array_merge_recursive() deep merge and scalar promotion
--FILE--
<?php
$a = array('x' => array('a' => 1), 'k' => 1);
$b = array('x' => array('b' => 2), 'k' => 2);
$r = array_merge_recursive($a, $b);
echo $r['x']['a'], "\n";
echo $r['x']['b'], "\n";
echo $r['k'][0], "\n";
echo $r['k'][1], "\n";
$s = array_merge_recursive(array('a' => 1), array('a' => array(2, 3)));
echo $s['a'][0], "\n";
echo $s['a'][1], "\n";
echo $s['a'][2], "\n";
$c = array(1, 2);
$d = array(3, 4);
$e = array_merge_recursive($c, $d);
echo count($e), "\n";
echo $e[0], "\n";
echo $e[1], "\n";
echo $e[2], "\n";
echo $e[3], "\n";
--EXPECT--
1
2
1
2
1
2
3
4
1
2
3
4
