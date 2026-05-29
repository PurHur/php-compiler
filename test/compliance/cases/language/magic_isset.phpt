--TEST--
__isset magic — isset() on object properties (Zend zend_object_handlers.c parity, #3298)
--FILE--
<?php
class M {
    private array $data = ['x' => 1];
    public function __isset(string $k): bool {
        return array_key_exists($k, $this->data);
    }
}
$m = new M;
var_dump(isset($m->x), isset($m->missing));
--EXPECT--
bool(true)
bool(false)
