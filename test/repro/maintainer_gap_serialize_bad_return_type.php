<?php

declare(strict_types=1);

class C
{
    public function __serialize()
    {
        return 1;
    }
}

try {
    serialize(new C());
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

class Ok
{
    public function __serialize(): array
    {
        return ['n' => 1];
    }

    public function __unserialize(array $data): void
    {
    }
}

echo serialize(new Ok()), "\n";
