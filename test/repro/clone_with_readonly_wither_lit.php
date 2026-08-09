<?php
class C {
  public function __construct(public readonly int $x, public readonly int $y) {}
  public function withX(): self { return clone($this, ['x' => 9]); }
}
try {
  $n = (new C(1,2))->withX();
  echo 'OK:', $n->x, '|', $n->y, "\n";
} catch (Throwable $e) {
  echo get_class($e), ':', $e->getMessage(), "\n";
}
