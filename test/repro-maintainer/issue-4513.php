<?php
class C {
    public int $x = 1;
    public string $y = 'a';
}

$c = new C();
$d = clone($c, ['x' => 2, 'y' => 'b']);
var_export([$d->x, $d->y]);
