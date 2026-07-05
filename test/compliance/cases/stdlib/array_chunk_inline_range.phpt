--TEST--
stdlib array_chunk() — inline range() haystack + preserve_keys (#11767, ext/standard/array.c)
--FILE--
<?php
$c = array_chunk(range(1, 5), 2, true);
echo count($c), "\n";
echo $c[0][1], "\n";
echo $c[1][3], "\n";
echo $c[2][4], "\n";
--EXPECT--
3
2
4
5
