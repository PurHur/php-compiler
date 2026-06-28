<?php

declare(strict_types=1);

// Repro for #13124 — RequestParseBodyException absent on Zend 8.2 reference profile.

if (class_exists('RequestParseBodyException', false)) {
    echo "fail: RequestParseBodyException registered on Zend 8.2 reference profile\n";
    exit(1);
}

echo "ok\n";
