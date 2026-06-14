--TEST--
stdlib array_column() runtime column/index keys (#4091)
--FILE--
<?php
$k = 'id';
$row = array(array('id' => 1, 'v' => 2));
echo json_encode(array_column($row, 'v', $k)), "\n";
$c = 'v';
echo json_encode(array_column($row, $c, 'id')), "\n";
--EXPECT--
{"1":2}
{"1":2}
