<?php

declare(strict_types=1);

class C {
    public int $a = 1;
}

$c = new C();
$d = (clone $c) with ['a' => 2];
echo $d->a, ':x', "\n";
