<?php

declare(strict_types=1);

class SerializeMagicProbe
{
    public int $v = 0;

    public function __serialize(): array
    {
        return ['v' => 42];
    }

    public function __unserialize(array $data): void
    {
        $this->v = (int) $data['v'];
    }
}

$obj = new SerializeMagicProbe();
$s = serialize($obj);
echo 's_type=' . gettype($s) . "\n";
$r = unserialize($s);
echo $r->v, "\n";
