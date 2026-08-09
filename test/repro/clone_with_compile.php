<?php

declare(strict_types=1);

class C {
    public int $a = 1;
}

$c = new C();
$d = clone($c, ['a' => 2]);
echo $d->a, ':x', "\n";
