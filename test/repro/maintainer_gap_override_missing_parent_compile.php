<?php

declare(strict_types=1);

/** Issue #12201 — #[\Override] without parent method compiles on Zend 8.2 reference profile. */
class A {}

class B extends A
{
    #[\Override]
    public function foo(): void {}
}

echo "ok\n";
