<?php

declare(strict_types=1);

/**
 * Maintainer repro for #8888 — grapheme NFD/NFC normalization parity.
 */

$nfc = "caf\u{00E9}";
$nfd = "cafe\u{0301}";
echo 'strlen_nfd=', grapheme_strlen($nfd), "\n";
echo 'strlen_nfc=', grapheme_strlen($nfc), "\n";
echo 'contains_nfd_needle_nfc=', (int) grapheme_str_contains($nfd, "\u{00E9}"), "\n";
echo 'contains_nfc_needle_nfd=', (int) grapheme_str_contains($nfc, "e\u{0301}"), "\n";
