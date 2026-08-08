<?php
/**
 * Repro #27591 — grapheme_levenshtein on PROFILE=8.5 (requires host php-intl).
 */
echo function_exists('grapheme_levenshtein') ? "exists\n" : "missing\n";
if (function_exists('grapheme_levenshtein')) {
    echo grapheme_levenshtein('café', 'cafe'), "\n";
    echo grapheme_levenshtein('kitten', 'sitting'), "\n";
}
