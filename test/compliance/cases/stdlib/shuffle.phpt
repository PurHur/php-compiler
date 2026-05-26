--TEST--
stdlib shuffle() on packed lists (#2310)
--FILE--
<?php
$a = array('c', 'a', 'b');
$ok = shuffle($a);
sort($a);
echo ($ok ? '1' : '0'), ':', implode(',', $a), "\n";
$single = array(42);
shuffle($single);
echo $single[0], "\n";
--EXPECT--
1:a,b,c
42
