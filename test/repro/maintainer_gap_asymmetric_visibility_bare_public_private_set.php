<?php

declare(strict_types=1);

class C {
    public private(set) string $name = 'x';
}

$c = new C();
echo $c->name, "\n";
