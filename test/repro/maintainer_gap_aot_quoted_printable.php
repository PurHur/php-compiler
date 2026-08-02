<?php

/**
 * Repro for #26899 — AOT quoted_printable round-trip must match Zend (no segfault).
 * Prefer literals so empty AOT `$argv[1]` cannot hide a broken helper TU.
 */
echo quoted_printable_decode(quoted_printable_encode('a=b')), "\n";
echo quoted_printable_decode(quoted_printable_encode("foo\r\nbar")), "\n";
$s = $argv[1] ?? '';
if ('' !== $s) {
    echo quoted_printable_decode(quoted_printable_encode($s)), "\n";
}
