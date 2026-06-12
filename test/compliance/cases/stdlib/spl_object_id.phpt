--TEST--
stdlib spl_object_id() — stable per-instance handle (#3172)
--FILE--
<?php
class A {}
$o1 = new A();
$o2 = new A();
$id1 = spl_object_id($o1);
$id2 = spl_object_id($o2);
echo (function_exists('spl_object_id')) ? "exists\n" : "missing\n";
echo ($id1 === spl_object_id($o1)) ? "same\n" : "changed\n";
echo ($id1 !== $id2) ? "distinct\n" : "equal\n";
echo ($id1 > 0 && $id2 > 0) ? "positive\n" : "nonpositive\n";
--EXPECT--
exists
same
distinct
positive
