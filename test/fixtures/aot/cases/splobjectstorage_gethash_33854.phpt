--TEST--
AOT: SplObjectStorage::getHash matches spl_object_hash (#33854)
--FILE--
<?php
$s = new SplObjectStorage();
$o = new stdClass();
echo ($s->getHash($o) === spl_object_hash($o)) ? "same\n" : "diff\n";
$a = new stdClass();
$b = new stdClass();
echo ($s->getHash($a) !== $s->getHash($b)) ? "distinct\n" : "equal\n";
--EXPECT--
same
distinct
