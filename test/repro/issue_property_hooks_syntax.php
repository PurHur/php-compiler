<?php

declare(strict_types=1);

// Issue #18445 — property hooks on default PHP 8.4 development profile.
class C {
    public int $x {
        get => $this->x;
        set => $this->x = $value;
    }
}

$c = new C();
$c->x = 5;
echo $c->x, "\n";
