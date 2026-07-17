--TEST--
Language: indirect ArrayAccess nested offset write — E_NOTICE not Error (#5460)
--FILE--
<?php
class C implements ArrayAccess {
    private $d = ['a' => ['b' => 1]];
    public function offsetExists($k): bool { return isset($this->d[$k]); }
    public function offsetGet($k): mixed { return $this->d[$k]; }
    public function offsetSet($k, $v): void { $this->d[$k] = $v; }
    public function offsetUnset($k): void { unset($this->d[$k]); }
}
$c = new C();
$c['a']['b'] = 2;
var_dump($c['a']);
--EXPECTF--
PHP Notice:  Indirect modification of overloaded element of C has no effect in %s on line %d
array(1) {
  ["b"]=>
  int(1)
}
