<?php
/** Repro #24250 — userland ArrayAccess::offsetUnset via private array storage. */
class Bag implements ArrayAccess {
    private array $d = ['x' => 1];

    public function offsetExists($o): bool
    {
        return isset($this->d[$o]);
    }

    public function offsetGet($o): mixed
    {
        return $this->d[$o];
    }

    public function offsetSet($o, $v): void
    {
        $this->d[$o] = $v;
    }

    public function offsetUnset($o): void
    {
        unset($this->d[$o]);
    }
}

$b = new Bag();
unset($b['x']);
var_export(isset($b['x']));
echo "\n";
var_export($b->offsetExists('x'));
echo "\n";
