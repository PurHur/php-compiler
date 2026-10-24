<?php
class Foo {} class Bar {}
function f($a, $b) { return $a + $b; }
foreach ([[new Foo, 1], [2.5, new Bar], [new Foo, new Bar], [new Foo, "s"], [[1], new Foo], [null, new Foo], [true, new Foo]] as [$a, $b]) {
  try { var_dump(f($a, $b)); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
}
