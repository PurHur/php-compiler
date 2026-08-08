--TEST--
stdlib grapheme_levenshtein() JIT — compile-time fold (#6998, #27591)
--SKIPIF--
<?php
if (!extension_loaded('intl')) die('skip host php-intl required');
if (getenv('PHP_COMPILER_PROFILE') !== '8.5'
    && version_compare(PHPCompiler\CompilerVersion::VERSION, '8.5.0', '<')
) {
    die('skip requires PHP_COMPILER_PROFILE=8.5 or CompilerVersion≥8.5');
}
?>
--FILE--
<?php
echo (int) function_exists('grapheme_levenshtein'), "\n";
echo grapheme_levenshtein('kitten', 'sitting'), "\n";
echo grapheme_levenshtein('café', 'café'), "\n";
--EXPECT--
1
3
0
