<?php
// #36366: typed method local $t = 0 then foreach $t += … — AOT Undefined variable $t
class Item {
    public function __construct(private int $p) {}
    public function price(): int { return $this->p; }
}
class Bundle {
    public function __construct(private array $items) {}
    public function price(): int {
        $t = 0;
        foreach ($this->items as $i) {
            $t += $i->price();
        }
        return $t;
    }
}
$b = new Bundle([new Item(100), new Item(50)]);
echo $b->price(), "\n";
