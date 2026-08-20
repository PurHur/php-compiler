<?php

declare(strict_types=1);

/**
 * Repro #32741 — is_file()/file_exists() always false under thin AOT.
 *
 * Fixture is created by the test harness (mkdir in script breaks thin AOT IR for mkdir).
 * php-src: ext/standard/filestat.c
 */
$base = 'test/compliance/cases/stdlib/copy_fixture';
$path = $base.'/source.txt';

$ok = is_file($path) && file_exists($path) && is_file($path) === file_exists($path);
echo $ok ? "is_file_ok\n" : "is_file_bad\n";

exit($ok ? 0 : 1);
