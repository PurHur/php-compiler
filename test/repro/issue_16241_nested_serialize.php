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
$r = unserialize(serialize($obj));
echo $r->v, "\n";
