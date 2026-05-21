--TEST--
stdlib count() JIT for arrays
--FILE--
<?php
$a = array(1, 2, 3);
echo count($a), "\n";
echo count(array()), "\n";
$b = array('a', 'b');
echo count($b), "\n";
for ($i = 0; $i < count($a); $i++) {
    echo $a[$i], "\n";
}
--EXPECT--
3
0
2
1
2
3
