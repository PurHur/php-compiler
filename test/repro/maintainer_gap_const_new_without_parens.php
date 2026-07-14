<?php

declare(strict_types=1);

/**
 * Issue #18816 — class constant `new Foo` without `()` on PHP 8.4 forward profile.
 */

class Foo {
    public function __construct(public int $n = 7) {}
}

class Holder {
    public const BAR = new Foo;
}

echo Holder::BAR->n, "\n";
echo get_class(Holder::BAR), "\n";
