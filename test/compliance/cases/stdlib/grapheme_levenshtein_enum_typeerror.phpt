--TEST--
stdlib grapheme_levenshtein() — enum case operand TypeError (#6998, #5914, #27591)
--SKIPIF--
<?php
if (!extension_loaded('intl')) die('skip host php-intl required');
if (getenv('PHP_COMPILER_PROFILE') !== '8.5'
    && version_compare(PHPCompiler\CompilerVersion::VERSION, '8.5.0', '<')
) {
    die('skip requires PHP_COMPILER_PROFILE=8.5 or CompilerVersion≥8.5');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
enum Es: string { case B = 'hi'; }
try {
    grapheme_levenshtein(Es::B, 'h');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
grapheme_levenshtein(): Argument #1 ($string1) must be of type string, Es given
