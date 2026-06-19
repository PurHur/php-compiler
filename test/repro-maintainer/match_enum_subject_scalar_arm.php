<?php
/** Maintainer repro for #10030 / #9716 — match enum-case subject must not match scalar arms. */
declare(strict_types=1);

enum F: int { case X = 1; }

echo match (F::X) {
    1 => 'int_hit',
    F::X => 'case_hit',
    default => 'miss',
}, "\n";
