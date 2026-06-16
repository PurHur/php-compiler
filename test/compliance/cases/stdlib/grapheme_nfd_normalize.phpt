--TEST--
stdlib grapheme_str_contains()/grapheme_strlen() — NFD/NFC normalization parity (#8888, ext/intl/grapheme)
--FILE--
<?php
$nfc = "caf\u{00E9}";
$nfd = "cafe\u{0301}";
echo grapheme_strlen($nfd), "\n";
echo grapheme_strlen($nfc), "\n";
echo (int) grapheme_str_contains($nfd, "\u{00E9}"), "\n";
echo (int) grapheme_str_contains($nfc, "e\u{0301}"), "\n";
echo (int) grapheme_str_contains($nfd, $nfc), "\n";
--EXPECT--
4
4
1
1
1
