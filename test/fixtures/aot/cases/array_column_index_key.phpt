--TEST--
AOT: array_column() index_key associative result
--FILE--
<?php
$rows = array(
    array('id' => 10, 'name' => 'x'),
    array('id' => 20, 'name' => 'y'),
);
$byId = array_column($rows, 'name', 'id');
echo count($byId), "\n";
echo $byId[10], "\n";
echo $byId[20], "\n";
--EXPECT--
2
x
y
