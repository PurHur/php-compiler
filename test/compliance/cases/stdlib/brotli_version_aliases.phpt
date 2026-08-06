--TEST--
stdlib BROTLI_VERSION_* + Brotli\compress aliases (#28092, kjdev/php-ext-brotli)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsBrotli()) {
    die('skip brotli withheld on reference profile (#17563)');
}
--FILE--
<?php
echo defined('BROTLI_VERSION_TEXT') ? '1' : '0';
echo "\n";
echo defined('BROTLI_VERSION_NUMBER') ? '1' : '0';
echo "\n";
echo defined('BROTLI_DICTIONARY_SUPPORT') ? '1' : '0';
echo "\n";
echo function_exists('Brotli\\compress') ? '1' : '0';
echo "\n";
echo function_exists('Brotli\\uncompress') ? '1' : '0';
echo "\n";
$plain = 'ns-alias';
$c = \Brotli\compress($plain);
echo (\Brotli\uncompress($c) === $plain) ? '1' : '0';
echo "\n";
?>
--EXPECT--
1
1
1
1
1
1
