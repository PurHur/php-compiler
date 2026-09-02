<?php
// #36221 program: inheritance + overrides + parent:: + instanceof
class Animal {
    protected string $name;
    public function __construct(string $name) { $this->name = $name; }
    public function speak(): string { return $this->name . ':...'; }
    public function tag(): string { return 'Animal:' . $this->name; }
}
class Dog extends Animal {
    private int $barkCount = 0;
    public function speak(): string {
        $this->barkCount++;
        return $this->name . ':woof#' . $this->barkCount;
    }
    public function tag(): string { return 'Dog>' . parent::tag(); }
}
class Cat extends Animal {
    public function speak(): string { return $this->name . ':meow'; }
}
$zoo = [new Dog('Rex'), new Cat('Mia'), new Dog('Rex')];
$lines = [];
foreach ($zoo as $a) {
    $kind = $a instanceof Dog ? 'D' : ($a instanceof Cat ? 'C' : 'A');
    $lines[] = $a->speak() . '|' . $a->tag() . '|' . $kind;
}
$out = implode("\n", $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
