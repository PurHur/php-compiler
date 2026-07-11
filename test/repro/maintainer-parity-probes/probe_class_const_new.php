<?php

declare(strict_types=1);

class Foo {
    public function __construct(public int $n = 0) {}
}

class Holder {
    public const BAR = new Foo(42);
}

echo Holder::BAR->n === 42 ? "42\n" : "fail\n";
echo Holder::BAR === Holder::BAR ? "1\n" : "0\n";
