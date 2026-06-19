<?php
declare(strict_types=1);

interface I {
    public const X = 1;
}

class C implements I {
    #[\Override]
    public const X = 2;
}

echo C::X, "\n";
