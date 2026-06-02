--TEST--
stdlib array_column() null index_key reindexes list (#4220)
--FILE--
<?php
$d = array(
    array('id' => 1, 'n' => 'a'),
    array('id' => 2, 'n' => 'b'),
);
$names = array_column($d, 'n', null);
echo count($names), "\n";
echo $names[0], "\n";
echo $names[1], "\n";
--EXPECT--
2
a
b
