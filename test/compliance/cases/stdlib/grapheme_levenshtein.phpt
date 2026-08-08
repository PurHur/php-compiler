--TEST--
stdlib grapheme_levenshtein() — grapheme cluster edit distance (#6998, #27591)
--SKIPIF--
<?php
if (!extension_loaded('intl')) die('skip host php-intl required');
if (getenv('PHP_COMPILER_PROFILE') !== '8.5'
    && version_compare(PHPCompiler\CompilerVersion::VERSION, '8.5.0', '<')
) {
    // Gate is PROFILE≥8.5; default CI profile is 8.4.0-dev — run under _profile_85 instead.
    die('skip requires PHP_COMPILER_PROFILE=8.5 or CompilerVersion≥8.5');
}
?>
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
