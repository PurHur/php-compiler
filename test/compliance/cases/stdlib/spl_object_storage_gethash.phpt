--TEST--
SPL object-storage getHash matches Zend handle / spl_object_hash (#24292)
--FILE--
<?php
$o = new stdClass();
$s = new SplObjectStorage();
echo $s->getHash($o), "\n";
echo (spl_object_hash($o) === $s->getHash($o)) ? "same\n" : "diff\n";
echo spl_object_id($o), "\n";
$a = new stdClass();
$b = new stdClass();
echo ($s->getHash($a) !== $s->getHash($b)) ? "distinct\n" : "equal\n";
--EXPECT--
00000000000000010000000000000000
same
1
distinct
