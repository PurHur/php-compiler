<?php
class D {
    public function __construct(public private(set) int $x = 1) {}
}
$d = new D();
echo $d->x, "\n";
