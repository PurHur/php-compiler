--TEST--
stdlib array_column() index_key builds associative result (ext/standard/array.c)
--FILE--
<?php
$rows = array(
    array('id' => 1, 'name' => 'a'),
    array('id' => 2, 'name' => 'b'),
    array('id' => 3),
    array('name' => 'orphan'),
);
$byId = array_column($rows, 'name', 'id');
echo count($byId), "\n";
echo $byId[1], "\n";
echo $byId[2], "\n";
$dup = array_column(
    array(
        array('k' => 1, 'v' => 'first'),
        array('k' => 1, 'v' => 'second'),
    ),
    'v',
    'k'
);
echo $dup[1], "\n";
--EXPECT--
2
a
b
second
