--TEST--
stdlib __sleep / __wakeup and Serializable (VM, issue #3287)
--FILE--
<?php
class L implements Serializable {
    public int $n = 0;
    public function serialize(): string { return (string) $this->n; }
    public function unserialize(string $d): void { $this->n = (int) $d; }
}
$o = new L();
$o->n = 3;
echo unserialize(serialize($o))->n, "\n";

class B {
    public int $n = 0;
    public function __sleep(): array { return ['n']; }
    public function __wakeup(): void { $this->n *= 2; }
}
$o = new B();
$o->n = 1;
$r = unserialize(serialize($o));
echo $r->n, "\n";
--EXPECT--
3
2
