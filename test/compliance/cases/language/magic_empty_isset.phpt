--TEST--
empty() on object property uses __isset semantics (#3298)
--FILE--
<?php
class M {
    private array $data = [];
    public function __isset(string $k): bool {
        return array_key_exists($k, $this->data);
    }
}
$m = new M;
echo empty($m->x) ? "empty\n" : "not\n";
--EXPECT--
empty
