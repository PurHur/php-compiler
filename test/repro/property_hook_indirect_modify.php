<?php
class C {
    public array $items {
        get => $this->items ?? [];
        set => $this->items = $value;
    }
}
$c = new C();
$c->items[] = 1;
var_export($c->items);
