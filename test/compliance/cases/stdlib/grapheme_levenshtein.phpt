--TEST--
stdlib grapheme_levenshtein() — grapheme cluster edit distance (#6998, ext/intl/grapheme)
--FILE--
<?php
echo (int) function_exists('grapheme_levenshtein'), "\n";
$nfc = "caf\u{00E9}";
$nfd = "caf\u{0065}\u{0301}";
echo grapheme_levenshtein($nfc, $nfd), "\n";
echo grapheme_levenshtein('kitten', 'sitting'), "\n";
--EXPECT--
1
0
3
