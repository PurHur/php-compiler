--TEST--
AOT: trait method precedence via insteadof (issue #3238)
--FILE--
<?php
trait T1 { public function f(): int { return 1; } }
trait T2 { public function f(): int { return 99; } public function g(): int { return 2; } }
class C { use T1, T2 { T1::f insteadof T2; } }
$c = new C();
echo $c->f(), $c->g();
--EXPECT--
12
