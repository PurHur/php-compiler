<?php

declare(strict_types=1);

// Maintainer gap #17025 — microtime(null) coerces $as_float to false (ext/standard/microtime.c Z_PARAM_BOOL).
$s = microtime(null);
if (!\is_string($s)) {
    fwrite(STDERR, "fail: expected string, got ".gettype($s)."\n");
    exit(1);
}
if (!preg_match('/^\d+\.\d+ \d+$/', $s)) {
    fwrite(STDERR, "fail: unexpected microtime format: {$s}\n");
    exit(1);
}
echo "ok\n";
