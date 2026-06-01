--TEST--
AOT: trait method alias via as (issue #3238)
--FILE--
<?php
trait T { public function f(): int { return 1; } public function g(): int { return 2; } }
class C { use T { f as renamed; } }
$c = new C();
echo $c->renamed(), $c->g();
--EXPECT--
12
