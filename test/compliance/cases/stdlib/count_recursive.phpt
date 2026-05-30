--TEST--
stdlib count() COUNT_NORMAL and COUNT_RECURSIVE (issue #3511)
--FILE--
<?php
$a = array(1, array(2, 3));
echo count($a), "\n";
echo count($a, COUNT_RECURSIVE), "\n";
echo count($a, COUNT_NORMAL), "\n";
$nested = array('fruits' => array('a', 'b'), 'veggie' => array('carrot', 'pea'));
echo count($nested, COUNT_RECURSIVE), "\n";
--EXPECT--
2
4
2
6
