<?php
class A { public function f(): int { echo "CALLED\n"; return 1; } }
$x = null;
var_dump($x?->f());
$y = new A();
var_dump($y?->f());
