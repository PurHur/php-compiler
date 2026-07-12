<?php

declare(strict_types=1);

/**
 * Issue #17938 — ASCII subset must match UTF-8 when UTF-8 precedes ASCII.
 */
echo 'ascii_only=', mb_detect_encoding('abc', 'UTF-8,ASCII', true), "\n";
echo 'ascii_first=', mb_detect_encoding('abc', 'ASCII,UTF-8', true), "\n";
echo 'utf8_multibyte=', mb_detect_encoding("αβ", 'UTF-8,ASCII', true), "\n";
