<?php
class C { public function f($x) { echo "call\n"; return $x; } }

$c = null;
var_export($c?->f((function(){ echo "arg\n"; return 1; })()));
echo "\n";

$c = new C();
var_export($c?->f(2));
echo "\n";
