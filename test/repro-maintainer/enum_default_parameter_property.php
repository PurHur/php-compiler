<?php
declare(strict_types=1);

enum E: int { case A = 1; }

function f(E $e = E::A): void {
    echo $e->name, "\n";
}

class P {
    public E $e = E::A;
}

f();
$p = new P();
echo $p->e->name, "\n";
