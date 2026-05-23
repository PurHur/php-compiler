--TEST--
AOT: array_column() on list of associative arrays
--FILE--
<?php
$rows = array(
    array('id' => 10, 'name' => 'x'),
    array('id' => 20, 'name' => 'y'),
);
$ids = array_column($rows, 'id');
echo count($ids), "\n";
echo $ids[0], "\n";
echo $ids[1], "\n";
$names = array_column($rows, 'name');
echo $names[0], $names[1], "\n";
--EXPECT--
2
10
20
xy
