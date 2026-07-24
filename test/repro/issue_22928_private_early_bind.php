<?php
class A {
  private function f() { return "A"; }
  public function g() { return $this->f(); }
}
class B extends A {
  private function f() { return "B"; }
}
echo (new B())->g(), "\n";
echo (new A())->g(), "\n";
