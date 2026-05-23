--TEST--
stdlib array_column() JIT for list of associative arrays
--FILE--
<?php
$rows = array(
    array('id' => 1, 'name' => 'a'),
    array('id' => 2, 'name' => 'b'),
);
$ids = array_column($rows, 'id');
echo count($ids), "\n";
echo $ids[0], "\n";
echo $ids[1], "\n";
$names = array_column($rows, 'name');
echo $names[0], "\n";
echo $names[1], "\n";
--EXPECT--
2
1
2
a
b
