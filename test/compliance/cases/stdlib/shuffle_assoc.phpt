--TEST--
stdlib shuffle() on associative arrays reindexes keys (#4460)
--FILE--
<?php
$a = array('x' => 10, 'y' => 20, 'z' => 30);
$ok = shuffle($a);
$keys = array_keys($a);
sort($keys);
$vals = array_values($a);
sort($vals);
echo ($ok ? '1' : '0'), ':', implode(',', $keys), ':', count($a), ':', implode(',', $vals), "\n";
--EXPECT--
1:0,1,2:3:10,20,30
