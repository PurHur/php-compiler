<?php

declare(strict_types=1);

/** Minimal #26367 follow-up — sibling call + ['k'=>false] mis-binds under AOT. */
function s($a, $b): void
{
    echo json_encode($b), "\n";
}

s(strtoupper('x'), ['k' => false]);
