<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
    public function m(): int {
        return $this->value;
    }
}

var_dump(Closure::fromCallable(E::A->m(...))());
