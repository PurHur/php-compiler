<?php
declare(strict_types=1);

class C {
    public int $x {
        get => $this->x;
        set => $this->x = $value;
    }
    public string $y = 'a';
    private int $x = 1;
}

$c = new C();
echo 'compile-ok x=' . $c->x . ' y=' . $c->y . "\n";
