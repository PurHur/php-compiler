--TEST--
Language: indirect modification of overloaded __get property throws Error (VM/JIT, #4673)
--FILE--
<?php
class Bag {
    private array $store = ['a' => 1];
    public function __get(string $name): array {
        return $this->store;
    }
}
$b = new Bag();
try {
    $b->store[] = 2;
} catch (Throwable $err) {
    echo get_class($err), ': ', $err->getMessage(), "\n";
}
class Ext {
    private array $internal = [0];
    public function __get(string $name): array {
        return $this->internal;
    }
}
$ext = new Ext();
try {
    $ext->data['k'] = 9;
} catch (Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), "\n";
}
--EXPECT--
Error: Indirect modification of overloaded property Bag::$store has no effect
Error: Indirect modification of overloaded property Ext::$data has no effect
