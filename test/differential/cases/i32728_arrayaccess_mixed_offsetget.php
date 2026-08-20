<?php
// #32728: ArrayAccess offsetGet(): mixed must return the payload under AOT.
class C32728 implements ArrayAccess {
    private $d = [];
    public function offsetExists($o): bool { return isset($this->d[$o]); }
    public function offsetGet($o): mixed { return $this->d[$o]; }
    public function offsetSet($o, $v): void { $this->d[$o] = $v; }
    public function offsetUnset($o): void { unset($this->d[$o]); }
}
$c = new C32728;
$c['k'] = 42;
echo $c['k'], "\n";
function f32728(): mixed { return 7; }
echo f32728(), "\n";
