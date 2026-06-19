<?php

declare(strict_types=1);

// Issue #9677 — duplicate backed enum values must fail at compile time (zend_enum.c).
enum E: int {
    case A = 1;
    case B = 1;
}
echo "should not run\n";
