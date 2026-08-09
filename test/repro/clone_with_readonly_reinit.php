<?php
// #29186 — clone($obj, [...]) must not reinit readonly props from global scope (Zend 8.5)
class C {
  public function __construct(public readonly int $x, public readonly int $y) {}
}
$o = new C(1, 2);
try {
  $n = clone($o, ['x' => 9]);
  echo 'OK:', $n->x, '|', $n->y;
} catch (Throwable $e) {
  echo get_class($e), ':', $e->getMessage();
}
echo "\n";

readonly class R {
  public function __construct(public int $a) {}
}
$r = new R(1);
try {
  $n = clone($r, ['a' => 7]);
  echo 'OKR:', $n->a;
} catch (Throwable $e) {
  echo get_class($e), ':', $e->getMessage();
}
echo "\n";
