--TEST--
stdlib serialize() rejects anonymous class even with __serialize (#12906, ext/standard/var.c)
--FILE--
<?php
try {
    serialize(new class {
        public function __serialize(): array
        {
            return ['x' => 1];
        }

        public function __unserialize(array $data): void
        {
        }
    });
    echo "no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

class Ser12906Named
{
    public function __serialize(): array
    {
        return ['n' => 1];
    }

    public function __unserialize(array $data): void
    {
    }
}

echo 'named ok=', strlen(serialize(new Ser12906Named())) > 0 ? '1' : '0', "\n";
?>
--EXPECT--
Exception:Serialization of 'class@anonymous' is not allowed
named ok=1
