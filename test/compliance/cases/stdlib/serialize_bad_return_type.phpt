--TEST--
stdlib serialize() __serialize() non-array return — TypeError (#10860, ext/standard/var.c)
--FILE--
<?php
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
--EXPECT--
TypeError: C::__serialize() must return an array
O:2:"Ok":1:{s:1:"n";i:1;}
