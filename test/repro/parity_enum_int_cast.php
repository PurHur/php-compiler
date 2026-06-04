<?php
declare(strict_types=1);

/** Issue #5791 / #5714 — (int) cast on backed enum case: Zend warns and yields 1, not backing scalar. */

enum E: int
{
    case A = 42;
}

echo (int) E::A, "\n";
