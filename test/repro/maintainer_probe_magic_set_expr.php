<?php
class A {
  public int $calls = 0;
  public function __set($k, $v) { $this->calls++; echo "set\n"; }
}
$a = new A();
$r = ($a->x = 5);
echo 'calls=', $a->calls, ' r=', $r, "\n";
