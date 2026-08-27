<?php
// AOT: get_object_vars($this) from subclass must compile and omit parent private (#35479).
class A { public $a=1; protected $b=2; private $c=3; }
class B extends A {
  public function g(){ $v=get_object_vars($this); ksort($v); return json_encode($v);}
}
echo (new B)->g();
