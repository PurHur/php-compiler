--TEST--
language: magic __get via dynamic property name $obj->$name (issue #4066)
--FILE--
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
--EXPECT--
1box
