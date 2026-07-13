<?php

declare(strict_types=1);

/**
 * Issue #18544 — parse_ini_file() must emit syntax Warning on parse failure (ext/standard/ini.c).
 */

$tmp = tempnam(sys_get_temp_dir(), 'ini');
if (false === $tmp) {
    echo "fail: tempnam\n";
    exit(1);
}
file_put_contents($tmp, "on=1\noff=0\n");

$warnings = 0;
set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
    ++$warnings;
    echo "WARN: {$message}\n";

    return true;
});
$result = parse_ini_file($tmp);
restore_error_handler();
unlink($tmp);

if (false !== $result) {
    echo "fail: expected false result\n";
    var_export($result);
    echo "\n";
    exit(1);
}
if (1 !== $warnings) {
    echo "fail: expected 1 warning, got {$warnings}\n";
    exit(1);
}

echo "ok\n";
