--TEST--
AOT: ArrayAccess offsetGet(): mixed returns payload (#32728)
--FILE--
<?php
class C implements ArrayAccess {
    private $d = [];
    public function offsetExists($o): bool { return isset($this->d[$o]); }
    public function offsetGet($o): mixed { return $this->d[$o]; }
    public function offsetSet($o, $v): void { $this->d[$o] = $v; }
    public function offsetUnset($o): void { unset($this->d[$o]); }
}
$c = new C;
$c['a'] = 1;
echo $c['a'], "\n";
function f(): mixed { return 7; }
echo f(), "\n";
?>
--EXPECT--
1
7
