<?php

declare(strict_types=1);

/**
 * Property-derived float distance under strict_types — sqrt() must accept
 * boxed TYPE_NATIVE_DOUBLE tags (#36386 / #20651).
 */

final class Body36386
{
    public function __construct(
        public float $x,
        public float $y,
        public float $z,
    ) {
    }
}

$b = new Body36386(4.84, -1.16, -0.10);
$b2 = new Body36386(8.34, 4.12, -0.40);
$dx = $b->x - $b2->x;
$dy = $b->y - $b2->y;
$dz = $b->z - $b2->z;
$s = $dx * $dx + $dy * $dy + $dz * $dz;
echo sqrt($s), "\n";
