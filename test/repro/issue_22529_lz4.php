<?php

declare(strict_types=1);

// Repro #22529 — lz4_compress / lz4_uncompress PECL surface.

if (!function_exists('lz4_compress') || !function_exists('lz4_uncompress')) {
    fwrite(STDERR, "fail: missing lz4 builtins\n");
    exit(1);
}

$blob = lz4_compress('hello');
if (!is_string($blob)) {
    fwrite(STDERR, "fail: compress\n");
    exit(1);
}
$out = lz4_uncompress($blob);
if ('hello' !== $out) {
    fwrite(STDERR, "fail: round-trip got ".var_export($out, true)."\n");
    exit(1);
}

echo "ok\n";
