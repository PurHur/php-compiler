<?php
/**
 * Repro #27594 — SQLite3Stmt::busy() is PHP 8.5-only (migration85.new-functions).
 *
 * PROFILE=8.4 must withhold method_exists; PROFILE=8.5 must advertise it.
 *
 * php-src: ext/sqlite3/sqlite3.stub.php (PHP-8.4 vs PHP-8.5)
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_27594_sqlite3stmt_busy_phantom.php
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_27594_sqlite3stmt_busy_phantom.php
 */
$has = class_exists('SQLite3Stmt') && method_exists('SQLite3Stmt', 'busy');
$profile = getenv('PHP_COMPILER_PROFILE');
$profile = \is_string($profile) && '' !== $profile ? $profile : '(default)';
echo 'profile=', $profile, PHP_EOL;
if ('8.5' === $profile || str_starts_with($profile, '8.5.')) {
    echo $has ? "present\n" : "missing\n";
} else {
    echo $has ? "phantom\n" : "ok\n";
}
