--TEST--
stdlib serialize() legacy Serializable recursion — finite C: blob not OOM (#18428, ext/standard/var.c)
--FILE--
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

$s = serialize(new RecursiveSerializable());
echo 'len=', strlen($s), "\n";
echo $s, "\n";
--EXPECT--
len=71
C:21:"RecursiveSerializable":37:{C:21:"RecursiveSerializable":4:{r:2;}}
