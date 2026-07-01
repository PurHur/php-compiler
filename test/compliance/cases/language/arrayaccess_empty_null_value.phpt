--TEST--
Language: ArrayAccess empty() — offsetExists true + null value is empty (#14798, Zend/zend_operators.c)
--FILE--
<?php
class Box implements ArrayAccess {
    private array $data = ['x' => null];

    public function offsetExists(mixed $offset): bool {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed {
        return $this->data[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void {
        unset($this->data[$offset]);
    }
}

$box = new Box();
echo empty($box['x']) ? "empty=1\n" : "empty=0\n";
echo isset($box['x']) ? "isset=1\n" : "isset=0\n";
--EXPECT--
empty=1
isset=1
