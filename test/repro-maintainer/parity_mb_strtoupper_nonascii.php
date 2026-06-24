<?php
declare(strict_types=1);

// Issue #11146 / #11129 — mb_strtoupper/mb_strtolower/mb_convert_case UTF-8 case (ext/mbstring/mbstring.c).

$upper = mb_strtoupper('über', 'UTF-8');
if ('ÜBER' !== $upper) {
    echo "FAIL upper über: {$upper}\n";
    exit(1);
}

$lower = mb_strtolower('ÜBER', 'UTF-8');
if ('über' !== $lower) {
    echo "FAIL lower ÜBER: {$lower}\n";
    exit(1);
}

$title = mb_convert_case('über', MB_CASE_TITLE, 'UTF-8');
if ('Über' !== $title) {
    echo "FAIL title über: {$title}\n";
    exit(1);
}

$greek = mb_strtoupper('α', 'UTF-8');
if ('Α' !== $greek) {
    echo "FAIL greek alpha: {$greek}\n";
    exit(1);
}

echo "OK\n";
