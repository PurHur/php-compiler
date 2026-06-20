<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
    public function m(): int { return $this->value; }
}

$c = Closure::bind(function (): int { return $this->value; }, E::A, E::class);
echo $c(), "\n";
