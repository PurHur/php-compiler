--TEST--
stdlib unserialize() legacy Serializable::unserialize() (VM, issue #4772)
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
--EXPECT--
3
