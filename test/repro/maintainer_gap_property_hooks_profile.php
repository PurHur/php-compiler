<?php

declare(strict_types=1);

// Issue #18531 — property hooks must parse-error on default 8.2 reference profile.
class C {
    public int $p {
        get => 42;
    }
}

$c = new C();
echo $c->p, "\n";
