--TEST--
stdlib array_column() null column_key returns row copies (#4306)
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
$mixed = array_column(array(1, array('a' => 1)), null);
echo $mixed[0], "\n";
$mixedRow = $mixed[1];
echo $mixedRow['a'], "\n";
$keyed = array_column(
    array(
        array('id' => 1, 'n' => 'a'),
        array('id' => 2, 'n' => 'b'),
    ),
    null,
    'id'
);
echo count($keyed), "\n";
$keyed1 = $keyed[1];
$keyed2 = $keyed[2];
echo $keyed1['n'], "\n";
echo $keyed2['n'], "\n";
--EXPECT--
2
1
2
1
1
2
a
b
