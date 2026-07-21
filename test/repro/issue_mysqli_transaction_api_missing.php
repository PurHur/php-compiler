<?php

declare(strict_types=1);

/**
 * Repro #21825 — mysqli transaction API registration.
 */
$funcs = [
    'mysqli_begin_transaction',
    'mysqli_commit',
    'mysqli_rollback',
    'mysqli_savepoint',
    'mysqli_release_savepoint',
    'mysqli_autocommit',
];
foreach ($funcs as $fn) {
    echo $fn, ':', function_exists($fn) ? 'yes' : 'no', "\n";
}
