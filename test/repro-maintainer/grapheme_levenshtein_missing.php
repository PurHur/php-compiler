<?php
declare(strict_types=1);

// Issue #22661 — Zend/php-src never ships grapheme_levenshtein(); must stay missing.
if (function_exists('grapheme_levenshtein')) {
    fwrite(STDERR, "PHANTOM grapheme_levenshtein still registered\n");
    exit(1);
}

echo "ok\n";
