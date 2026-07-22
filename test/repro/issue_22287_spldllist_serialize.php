<?php
foreach (["SplDoublyLinkedList","SplQueue","SplStack"] as $c) {
  $o = new $c();
  echo $c, " __serialize=", method_exists($o, "__serialize") ? "Y" : "N",
    " serialize=", method_exists($o, "serialize") ? "Y" : "N", PHP_EOL;
}
$d = new SplDoublyLinkedList();
$d->push("a"); $d->push("b");
$s = serialize($d);
$u = unserialize($s);
echo "count=", $u->count(), " values=";
$vals=[]; foreach ($u as $v) { $vals[]=$v; }
echo implode(",", $vals), PHP_EOL;
