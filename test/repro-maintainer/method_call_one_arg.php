<?php
class C { public function f($x) { return $x; } }
$c = new C();
var_dump($c->f(2));
