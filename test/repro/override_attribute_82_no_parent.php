<?php

declare(strict_types=1);

/** Issue #11559 — #[\Override] without parent method compiles when validation is off (PHP 8.2). */
class A {}

class B extends A
{
    #[\Override]
    public function foo(): void {}
}

echo "ok\n";
