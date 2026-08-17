--TEST--
unset() on instance object array property dims (typed/untyped, public/private) (#24250, #31818)
--FILE--
<?php
class C {
    public array $t = ['x' => 1, 'y' => 2];
    private array $pt = ['a' => 1];
    public $u = ['p' => 1, 'q' => 2];
    private $pu = ['b' => 1];
    public function wipe(): void {
        unset($this->t['x'], $this->pt['a'], $this->u['p'], $this->pu['b']);
    }
    public function dumpPt() { return $this->pt; }
    public function dumpPu() { return $this->pu; }
}
class Bag implements ArrayAccess {
    private array $d = ['x' => 1];
    public function offsetExists($o): bool { return isset($this->d[$o]); }
    public function offsetGet($o): mixed { return $this->d[$o]; }
    public function offsetSet($o, $v): void { $this->d[$o] = $v; }
    public function offsetUnset($o): void { unset($this->d[$o]); }
}
class StaticBag {
    public static array $t = ['x' => 1, 'y' => 2];
    public static $u = ['p' => 1, 'q' => 2];
}
$c = new C();
$c->wipe();
unset($c->t['y'], $c->u['q']);
echo 't=', count($c->t), ' pt=', count($c->dumpPt()), ' u=', count($c->u), ' pu=', count($c->dumpPu()), "\n";
$b = new Bag();
unset($b['x']);
echo 'aa=', isset($b['x']) ? '1' : '0', $b->offsetExists('x') ? '1' : '0', "\n";
unset(StaticBag::$t['y'], StaticBag::$u['q']);
echo 'st=', count(StaticBag::$t), ' su=', count(StaticBag::$u), "\n";
--EXPECT--
t=0 pt=0 u=0 pu=0
aa=00
st=1 su=1
