--TEST--
Integration: array_search, array_sum, array_flip
--FILE--
<?php
$scores = array('alice' => 10, 'bob' => 20, 'carol' => 10);
echo array_search(20, $scores), "\n";
echo array_sum(array(10, 20, 10)), "\n";
$byId = array_flip(array('x' => 1, 'y' => 2));
echo $byId[1], $byId[2], "\n";
--EXPECT--
bob
40
xy
