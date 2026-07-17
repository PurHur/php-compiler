--TEST--
Language: indirect modification of ArrayAccess element — E_NOTICE (#5460)
--FILE--
<?php
class C implements ArrayAccess {
    private array $data = [];
    public function offsetExists(mixed $offset): bool { return true; }
    public function offsetGet(mixed $offset): mixed { return $this->data[$offset] ?? []; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->data[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->data[$offset]); }
}
$c = new C();
$c[0][] = 1;
echo "ok\n";
--EXPECTF--
PHP Notice:  Indirect modification of overloaded element of C has no effect in %s on line %d
ok
