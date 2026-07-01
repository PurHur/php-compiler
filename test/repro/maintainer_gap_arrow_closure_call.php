<?php

declare(strict_types=1);

class C {
    private int $v = 2;

    private function m(): int {
        return $this->v;
    }
}

$c = new C();
$arrow = fn () => $this->m();
var_dump($arrow->call($c));
