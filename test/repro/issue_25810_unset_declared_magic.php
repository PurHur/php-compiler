<?php
// #25810 — unset() on declared public property must route later access through __get/__isset/__set
error_reporting(E_ALL);
class A {
    public $x = 1;
    public function __get($n) { return "get:$n"; }
    public function __isset($n) { return true; }
    public function __set($n, $v) { echo "set:$n\n"; $this->$n = $v; }
}
$a = new A();
unset($a->x);
echo "read=", var_export($a->x, true), "\n";
echo "isset=", var_export(isset($a->x), true), "\n";
$a->x = 9;
echo "after_set=", var_export($a->x, true), "\n";
