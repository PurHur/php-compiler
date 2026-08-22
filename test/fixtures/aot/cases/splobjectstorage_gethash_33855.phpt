--TEST--
AOT: SplObjectStorage::getHash matches spl_object_hash (#33855)
--FILE--
<?php
$o = new stdClass();
$s = new SplObjectStorage();
echo (spl_object_hash($o) === $s->getHash($o)) ? "same\n" : "diff\n";

$a = new stdClass();
$b = new stdClass();
echo ($s->getHash($a) !== $s->getHash($b)) ? "distinct\n" : "equal\n";

enum E {
    case A;
}
echo ($s->getHash(E::A) === spl_object_hash(E::A)) ? "enum_same\n" : "enum_diff\n";
--EXPECT--
same
distinct
enum_same
