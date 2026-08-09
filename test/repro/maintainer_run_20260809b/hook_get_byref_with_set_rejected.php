<?php
class H {
    private array $b = [1, 2];
    public array $a {
        &get => $this->b;
        set => $this->b = $value;
    }
}
$h = new H;
$r = &$h->a;
$r[] = 3;
echo json_encode($h->a), "\n";
