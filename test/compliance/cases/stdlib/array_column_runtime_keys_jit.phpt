--TEST--
stdlib array_column() runtime keys JIT (#4091)
--JIT--
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
