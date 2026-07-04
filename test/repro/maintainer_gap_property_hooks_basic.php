<?php

declare(strict_types=1);

class C {
    public string $x {
        get => $this->x ?? 'd';
        set(string $v) {
            $this->x = strtoupper($v);
        }
    }
}

$c = new C();
$c->x = 'hi';
echo $c->x, "\n";
