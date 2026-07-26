<?php
error_reporting(E_ALL);
$warned = [];
set_error_handler(function ($n, $m) use (&$warned) { $warned[] = $m; return true; });
class A { public $x = 1; function f() { return $this->x; } }
$c = Closure::fromCallable([new A(), "f"]);
$r = $c->bindTo(null);
echo "fromCallable result=" . ($r === null ? "NULL" : "C") . " warns=" . count($warned) . " msg=" . ($warned[0] ?? "") . "\n";
$warned = [];
$c2 = (new A())->f(...);
$r2 = $c2->bindTo(null);
echo "fcc result=" . ($r2 === null ? "NULL" : "C") . " warns=" . count($warned) . " msg=" . ($warned[0] ?? "") . "\n";
$warned = [];
$u = (function () { return $this->x; })->bindTo(new A());
$r3 = $u->bindTo(null);
echo "user result=" . ($r3 === null ? "NULL" : "C") . " warns=" . count($warned) . " msg=" . ($warned[0] ?? "") . "\n";
