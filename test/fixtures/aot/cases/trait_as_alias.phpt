--TEST--
AOT: trait method alias via as keeps original (#22718 / #3238)
--FILE--
<?php
trait T { public function f(): int { return 1; } public function g(): int { return 2; } }
class C { use T { f as renamed; } }
$c = new C();
echo $c->f(), $c->renamed(), $c->g();
--EXPECT--
112
