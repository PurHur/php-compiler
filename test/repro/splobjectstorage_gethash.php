<?php
/**
 * #24292 — SplObjectStorage::getHash must match Zend spl_object_hash / handle numbering.
 */
$o = new stdClass();
$s = new SplObjectStorage();
echo $s->getHash($o), "\n";
echo spl_object_hash($o), "\n";
echo (spl_object_hash($o) === $s->getHash($o)) ? "same\n" : "diff\n";
echo spl_object_id($o), "\n";

$a = new stdClass();
$b = new stdClass();
echo $s->getHash($a), "\n";
echo $s->getHash($b), "\n";
echo ($s->getHash($a) !== $s->getHash($b)) ? "distinct\n" : "equal\n";

enum E {
    case A;
}
echo ($s->getHash(E::A) === spl_object_hash(E::A)) ? "enum_same\n" : "enum_diff\n";
