--TEST--
Language: __get returning ArrayAccess object allows dim write (#20005, zend_object_handlers)
--FILE--
<?php
class Box implements ArrayAccess {
    public $d = [];
    public function offsetExists(mixed $offset): bool { return isset($this->d[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->d[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->d[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->d[$offset]); }
}
class A {
    public function __get(string $name): Box {
        return new Box();
    }
}
$a = new A();
$a->foo['x'] = 2;
echo "ok\n";
--EXPECT--
ok
