<?php
declare(strict_types=1);

enum F: int { case X = 1; }

echo match (F::X) {
    1 => 'int_hit',
    F::X => 'case_hit',
    default => 'miss',
}, "\n";
