<?php
// Companion to maintainer_gap_unset_object_array_dim.php — static props + ArrayAccess symptom
error_reporting(E_ALL);

class StaticBag {
    public static array $t = ['x' => 1, 'y' => 2];
    public static $u = ['p' => 1, 'q' => 2];
}

unset(StaticBag::$t['y']);
unset(StaticBag::$u['q']);
echo 'static_t=';
var_export(StaticBag::$t);
echo "\nstatic_u=";
var_export(StaticBag::$u);
echo "\n";

class Store implements ArrayAccess {
    private array $d = [];
    public function offsetExists($o): bool { return isset($this->d[$o]); }
    public function offsetGet($o): mixed { return $this->d[$o]; }
    public function offsetSet($o, $v): void { $this->d[$o] = $v; }
    public function offsetUnset($o): void { unset($this->d[$o]); }
}

$s = new Store;
$s['k'] = 1;
unset($s['k']);
echo 'aa=' . (isset($s['k']) ? '1' : '0') . "\n";
