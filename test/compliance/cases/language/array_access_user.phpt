--TEST--
Language: ArrayAccess — $obj[$key] read/write/isset/unset (VM, #3331)
--FILE--
<?php
class Box implements ArrayAccess {
    private array $data = [];
    public function offsetExists(mixed $k): bool { return isset($this->data[$k]); }
    public function offsetGet(mixed $k): mixed { return $this->data[$k]; }
    public function offsetSet(mixed $k, mixed $v): void { $this->data[$k] = $v; }
    public function offsetUnset(mixed $k): void { unset($this->data[$k]); }
}
$b = new Box();
$b['x'] = 42;
echo $b['x'], "\n";
echo isset($b['x']) ? '1' : '0', "\n";
echo isset($b['missing']) ? '1' : '0', "\n";
unset($b['x']);
echo isset($b['x']) ? '1' : '0', "\n";
--EXPECT--
42
1
0
0
