--TEST--
stdlib __serialize / __unserialize roundtrip (VM, issue #1365)
--FILE--
<?php
class Box {
    private int $n = 0;

    public function __construct(int $n = 0)
    {
        $this->n = $n;
    }

    public function __serialize(): array
    {
        return ['n' => $this->n];
    }

    public function __unserialize(array $data): void
    {
        $this->n = $data['n'];
    }

    public function get(): int
    {
        return $this->n;
    }
}

$o = new Box(7);
$s = serialize($o);
echo $s, "\n";
$r = unserialize($s);
echo $r->get(), "\n";
echo serialize($r), "\n";
--EXPECT--
O:3:"Box":1:{s:1:"n";i:7;}
7
O:3:"Box":1:{s:1:"n";i:7;}
