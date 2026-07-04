--TEST--
stdlib unserialize(serialize($obj)) __serialize roundtrip (VM, issue #16241)
--FILE--
<?php
class Box {
    public int $v = 0;

    public function __serialize(): array
    {
        return ['v' => 7];
    }

    public function __unserialize(array $data): void
    {
        $this->v = (int) $data['v'];
    }
}

$obj = new Box();
$restored = unserialize(serialize($obj));
echo $restored->v, "\n";
--EXPECT--
7
