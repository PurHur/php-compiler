--TEST--
language: non-public instance magics warn and still dispatch (issue #26439)
--FILE--
<?php
error_reporting(E_ALL);
class Get {
    private function __get($n) { return "get:$n"; }
}
class Set {
    private function __set($n, $v) { echo "set:$n=$v\n"; }
}
class Is {
    protected function __isset($n) { return true; }
}
class Un {
    private function __unset($n) { echo "unset:$n\n"; }
}
class Call {
    private function __call($n, $a) { return "call:$n:" . count($a); }
}
class Ser {
    private function __serialize(): array { return []; }
    private function __unserialize(array $d): void {}
}
class Sl {
    private function __sleep() { return []; }
    private function __wakeup() {}
}
class Di {
    protected function __debugInfo() { return ['k' => 1]; }
}

$g = new Get;
echo $g->x, "\n";
$s = new Set;
$s->y = 2;
$i = new Is;
echo isset($i->z) ? "isset\n" : "no\n";
$u = new Un;
unset($u->w);
$c = new Call;
echo $c->foo('a'), "\n";
echo serialize(new Ser), "\n";
echo serialize(new Sl), "\n";
var_dump(new Di);
--EXPECTF--
Warning: The magic method Get::__get() must have public visibility in %s on line %d
Warning: The magic method Set::__set() must have public visibility in %s on line %d
Warning: The magic method Is::__isset() must have public visibility in %s on line %d
Warning: The magic method Un::__unset() must have public visibility in %s on line %d
Warning: The magic method Call::__call() must have public visibility in %s on line %d
Warning: The magic method Ser::__serialize() must have public visibility in %s on line %d
Warning: The magic method Ser::__unserialize() must have public visibility in %s on line %d
Warning: The magic method Sl::__sleep() must have public visibility in %s on line %d
Warning: The magic method Sl::__wakeup() must have public visibility in %s on line %d
Warning: The magic method Di::__debugInfo() must have public visibility in %s on line %d
get:x
set:y=2
isset
unset:w
call:foo:1
O:3:"Ser":0:{}
O:2:"Sl":0:{}
object(Di)#%d (1) {
  ["k"]=>
  int(1)
}
