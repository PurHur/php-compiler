<?php

declare(strict_types=1);

class Point {
    public int $x = 1;
    public int $y = 2;
}

$p = new Point();
$q = clone ($p, with: ['x' => 9]);
echo $q->x, ',', $q->y, "\n";
