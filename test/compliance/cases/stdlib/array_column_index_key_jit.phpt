--TEST--
stdlib array_column() index_key JIT with compile-time column keys
--JIT--
--FILE--
<?php
$rows = array(
    array('id' => 10, 'name' => 'x'),
    array('id' => 20, 'name' => 'y'),
);
$byId = array_column($rows, 'name', 'id');
echo $byId[10], "\n";
echo $byId[20], "\n";
--EXPECT--
x
y
