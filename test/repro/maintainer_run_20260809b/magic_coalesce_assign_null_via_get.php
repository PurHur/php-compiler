<?php
class C {
    private $store = ['x' => null];
    public function __isset($n) { echo "ISSET\n"; return array_key_exists($n, $this->store); }
    public function __get($n) { echo "GET\n"; return $this->store[$n]; }
    public function __set($n, $v) { echo "SET:$v\n"; $this->store[$n] = $v; }
}
$o = new C;
$o->x ??= 1;
echo "done\n";
