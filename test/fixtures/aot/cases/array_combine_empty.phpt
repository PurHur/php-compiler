--TEST--
AOT: array_combine() empty keys and values return [] (#4523)
--FILE--
<?php
$result = array_combine([], []);
echo gettype($result), "\n";
echo count($result), "\n";
--EXPECT--
array
0
