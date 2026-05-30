--TEST--
__unset magic — unset() on object properties (Zend zend_object_handlers.c parity, #3298)
--FILE--
<?php
class M {
    private array $data = ['x' => 1, 'y' => 2];
    public function __isset(string $k): bool {
        return array_key_exists($k, $this->data);
    }
    public function __unset(string $k): void {
        unset($this->data[$k]);
    }
}
$m = new M;
unset($m->x);
var_dump(isset($m->x), isset($m->y));
--EXPECT--
bool(false)
bool(true)
