<?php
class Box {
    private array $data = ['x' => 1];
    public function __get(string $name) {
        return $this->data[$name] ?? null;
    }
    public function __toString(): string {
        return 'box';
    }
}
$o = new Box();
$key = 'x';
echo $o->$key, (string) $o, "\n";
