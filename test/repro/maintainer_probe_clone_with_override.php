<?php
declare(strict_types=1);

class C {
    public int $x = 1;

    public function __clone(): void {
        $this->x = 99;
    }
}

$c = new C();
$d = clone($c, ['x' => 2]);
var_export([$c->x, $d->x]);
echo "\n";
