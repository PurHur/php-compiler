<?php

declare(strict_types=1);

// php-src Z_PARAM_PATH: null coerces to "" (#13406, ext/standard/filestat.c).

try {
    unlink(null);
} catch (Throwable $e) {
    echo 'fail: unlink(null) threw ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

if (false !== unlink(null)) {
    echo 'fail: unlink(null) must return false, got ', var_export(unlink(null), true), "\n";
    exit(1);
}

try {
    realpath(null);
} catch (Throwable $e) {
    echo 'fail: realpath(null) threw ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

$resolved = realpath(null);
if (!is_string($resolved) || '' === $resolved) {
    echo 'fail: realpath(null) must return cwd string, got ', var_export($resolved, true), "\n";
    exit(1);
}

$emptyResolved = realpath('');
if ($resolved !== $emptyResolved) {
    echo "fail: realpath(null) must match realpath('')\n";
    exit(1);
}

if (false !== file_exists(null)) {
    echo 'fail: file_exists(null) must be false, got ', var_export(file_exists(null), true), "\n";
    exit(1);
}

echo "ok\n";
