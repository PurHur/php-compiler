--TEST--
Magic property ??= consults __get (with or without __isset) (#29228, zend_object_handlers.c)
--FILE--
<?php
class WithIsset {
    private array $store = [];
    public function __isset($n) { echo "ISSET\n"; return array_key_exists($n, $this->store); }
    public function __get($n) { echo "GET\n"; return $this->store[$n]; }
    public function __set($n, $v) { echo "SET\n"; $this->store[$n] = $v; }
}
$o = new WithIsset;
$o->x ??= 1;
$o->x ??= 2;
echo "done-isset\n";

class NoIsset {
    private array $store = ['x' => 'v'];
    public function __get($n) { echo "GET:$n\n"; return $this->store[$n] ?? null; }
    public function __set($n, $v) { echo "SET:$n=$v\n"; $this->store[$n] = $v; }
}
$p = new NoIsset;
echo "isset=". (isset($p->x) ? 'Y' : 'N') . "\n";
$p->x ??= 'fallback';
echo "done-no-isset\n";

class NullViaGet {
    private $store = ['x' => null];
    public function __isset($n) { echo "ISSET\n"; return array_key_exists($n, $this->store); }
    public function __get($n) { echo "GET\n"; return $this->store[$n]; }
    public function __set($n, $v) { echo "SET:$v\n"; $this->store[$n] = $v; }
}
$n = new NullViaGet;
$n->x ??= 1;
echo "done-null\n";
--EXPECT--
ISSET
SET
ISSET
GET
done-isset
isset=N
GET:x
done-no-isset
ISSET
GET
SET:1
done-null
