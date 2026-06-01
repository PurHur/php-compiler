--TEST--
stdlib get_resource_id() JIT — stable stream handle (#3180)
--FILE--
<?php
$f1 = fopen('php://memory', 'r+');
$f2 = fopen('php://memory', 'r+');
$id1 = get_resource_id($f1);
$id2 = get_resource_id($f2);
echo ($id1 === get_resource_id($f1)) ? "same\n" : "changed\n";
echo ($id1 !== $id2) ? "distinct\n" : "equal\n";
echo ($id1 > 0 && $id2 > 0) ? "positive\n" : "nonpositive\n";
fclose($f1);
fclose($f2);
--EXPECT--
same
distinct
positive
