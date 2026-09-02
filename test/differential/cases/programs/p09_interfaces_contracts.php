<?php
// #36221 program: interfaces + multiple implementors
interface Named { public function name(): string; }
interface Priced { public function price(): int; }
interface Sku extends Named, Priced { public function sku(): string; }
class Product implements Sku {
    private string $sku;
    private string $name;
    private int $price;
    public function __construct(string $sku, string $name, int $price) {
        $this->sku = $sku;
        $this->name = $name;
        $this->price = $price;
    }
    public function sku(): string { return $this->sku; }
    public function name(): string { return $this->name; }
    public function price(): int { return $this->price; }
}
class Bundle implements Named, Priced {
    private string $name;
    private array $items;
    public function __construct(string $name, array $items) {
        $this->name = $name;
        $this->items = $items;
    }
    public function name(): string { return $this->name; }
    public function price(): int {
        $t = 0;
        foreach ($this->items as $i) { $t += $i->price(); }
        return $t;
    }
    public function skus(): string {
        $s = [];
        foreach ($this->items as $i) { $s[] = $i->sku(); }
        sort($s);
        return implode(',', $s);
    }
}
$p1 = new Product('a', 'Alpha', 100);
$p2 = new Product('b', 'Beta', 50);
$b = new Bundle('pack', [$p2, $p1]);
$out = $b->name() . ':' . $b->price() . ':' . $b->skus() . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
