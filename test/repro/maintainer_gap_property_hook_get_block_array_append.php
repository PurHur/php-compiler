<?php
class C {
    public array $items {
        get {
            return $this->items ?? [];
        }
        set {
            $this->items = $value;
        }
    }
}
$c = new C();
$c->items[] = 'a';
echo count($c->items), "\n";
echo $c->items[0], "\n";
