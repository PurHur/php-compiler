<?php

declare(strict_types=1);

/**
 * Repro for #10634 — addslashes()/stripslashes() NUL C-escape parity (php-src string.c).
 */

echo bin2hex(addslashes("a\0b")), "\n";
echo bin2hex(stripslashes('a\\0b')), "\n";
