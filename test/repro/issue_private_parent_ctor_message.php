<?php
class A { private function __construct() {} }
class B extends A {
  public function __construct() {
    try { parent::__construct(); }
    catch (Throwable $e) { echo $e->getMessage(), "\n"; }
  }
}
new B();
