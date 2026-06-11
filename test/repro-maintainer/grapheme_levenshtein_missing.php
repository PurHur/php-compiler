<?php
declare(strict_types=1);

if (!function_exists('grapheme_levenshtein')) {
    fwrite(STDERR, "MISSING grapheme_levenshtein\n");
    exit(1);
}

// NFC vs NFD of "café" — grapheme distance should be 0; byte levenshtein may differ
$nfc = "caf\u{00E9}";
$nfd = "caf\u{0065}\u{0301}";
echo grapheme_levenshtein($nfc, $nfd), "\n";
echo grapheme_levenshtein('kitten', 'sitting'), "\n";
