<?php
// #26826 — readonly class mutate inside try/catch must AOT-build and catch Error.
readonly class Point {
    public function __construct(public int $x, public int $y) {}
}
$p = new Point(1, 2);
echo $p->x + $p->y, "\n";
try {
    $p->x = 3;
} catch (Error $e) {
    echo "err\n";
}
