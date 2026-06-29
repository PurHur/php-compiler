<?php

declare(strict_types=1);

class C {
    public function __construct(public int $n = 0) {}
}

class Holder {
    public const X = new C(1);
}

var_dump(Holder::X->n);
