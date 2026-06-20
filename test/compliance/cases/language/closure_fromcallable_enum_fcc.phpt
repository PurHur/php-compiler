--TEST--
language: Closure::fromCallable(E::A->m(...)) inline FCC (#9769, zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
    public function m(): int {
        return $this->value;
    }
}

var_dump(Closure::fromCallable(E::A->m(...))());
--EXPECT--
int(1)
