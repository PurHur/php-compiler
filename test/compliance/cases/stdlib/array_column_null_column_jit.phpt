--TEST--
stdlib array_column() null column_key JIT (#4306)
--FILE--
<?php
$rows = array(
    array('x' => 1),
    array('x' => 2),
);
$out = array_column($rows, null);
echo count($out), "\n";
$row0 = $out[0];
$row1 = $out[1];
echo $row0['x'], "\n";
echo $row1['x'], "\n";
--EXPECT--
2
1
2
