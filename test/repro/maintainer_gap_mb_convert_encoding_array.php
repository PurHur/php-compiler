<?php

declare(strict_types=1);

// Issue #3222 — mb_convert_encoding() array|string first operand (ext/mbstring/mbstring.c).
$latin1 = ["\xE9", 'foo'];
$out = mb_convert_encoding($latin1, 'UTF-8', 'ISO-8859-1');
if (!is_array($out) || 'é' !== $out[0] || 'foo' !== $out[1]) {
    fwrite(STDERR, 'fail: array conversion '.var_export($out, true)."\n");
    exit(1);
}

enum E: string
{
    case A = 'x';
}
try {
    mb_convert_encoding(E::A, 'UTF-8');
    fwrite(STDERR, "fail: enum must TypeError\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'array|string')) {
        fwrite(STDERR, 'fail: message '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
