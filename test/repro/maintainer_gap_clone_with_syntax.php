<?php

declare(strict_types=1);

// Restored for CloneWithSyntaxReferenceProfileTest (#12987 / #29187).
class Point
{
    public int $x = 1;
    public int $y = 2;
}

$p = new Point();
$q = clone ($p, with: ['x' => 9]);
echo $q->x, ',', $q->y, "\n";
