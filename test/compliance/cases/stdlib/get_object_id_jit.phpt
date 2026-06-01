--TEST--
stdlib get_object_id() JIT — stable per-instance handle (#3537)
--FILE--
<?php
class A {}
$o1 = new A();
$o2 = new A();
$id1 = get_object_id($o1);
$id2 = get_object_id($o2);
echo ($id1 === get_object_id($o1)) ? "same\n" : "changed\n";
echo ($id1 !== $id2) ? "distinct\n" : "equal\n";
echo ($id1 > 0 && $id2 > 0) ? "positive\n" : "nonpositive\n";
--EXPECT--
same
distinct
positive
