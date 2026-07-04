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
$restored = unserialize(serialize($obj));
if (!$restored instanceof SerializeMagicProbe) {
    echo "fail: not instance\n";
    exit(1);
}
if (42 !== $restored->v) {
    echo 'fail: v=', var_export($restored->v, true), "\n";
    exit(1);
}
echo "serialize_unserialize_magic_ok\n";
