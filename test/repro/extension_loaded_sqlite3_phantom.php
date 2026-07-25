<?php
/**
 * Repro #22791 — extension_loaded('sqlite3') / SQLite3 / SQLite3Exception phantom on reference.
 *
 * Zend (no ext/sqlite3): all false.
 * VM reference: all false after fix.
 * Forward: PHP_COMPILER_PROFILE=8.4 → all true.
 */
declare(strict_types=1);

echo 'PROFILE=', getenv('PHP_COMPILER_PROFILE') ?: '(default)', "\n";
echo 'loaded=', extension_loaded('sqlite3') ? 'yes' : 'no', "\n";
echo 'SQLite3=', class_exists('SQLite3', false) ? 'yes' : 'no', "\n";
echo 'SQLite3Exception=', class_exists('SQLite3Exception', false) ? 'yes' : 'no', "\n";
