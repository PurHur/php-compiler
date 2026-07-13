<?php

declare(strict_types=1);

class RecursiveSerializable implements Serializable
{
    public function serialize(): string
    {
        return serialize($this);
    }

    public function unserialize(string $data): void
    {
    }
}

$start = microtime(true);
$result = serialize(new RecursiveSerializable());
$elapsed = microtime(true) - $start;

echo 'len='.\strlen($result)."\n";
echo 'elapsed='.round($elapsed, 3)."s\n";
echo 'prefix='.substr($result, 0, 40)."\n";
