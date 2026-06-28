<?php

// Issue #12906 — serialize() rejects anonymous classes (ext/standard/var.c).
try {
    $blob = serialize(new class {
        public function __serialize(): array
        {
            return ['x' => 1];
        }

        public function __unserialize(array $data): void
        {
        }
    });
    echo 'serialized len=', strlen($blob), "\n";
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

echo 'named len=', strlen(serialize(new Ser12906Named())), "\n";
