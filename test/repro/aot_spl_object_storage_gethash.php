<?php
// #33854 — SplObjectStorage::getHash must match spl_object_hash under AOT.
$s = new SplObjectStorage();
$o = new stdClass();
echo ($s->getHash($o) === spl_object_hash($o)) ? "same\n" : "diff\n";
$a = new stdClass();
$b = new stdClass();
echo ($s->getHash($a) !== $s->getHash($b)) ? "distinct\n" : "equal\n";
