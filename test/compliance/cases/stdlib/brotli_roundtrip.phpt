--TEST--
stdlib brotli_compress/brotli_uncompress round-trip via libbrotli FFI (#6814, kjdev/php-ext-brotli)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsBrotli()) {
    die('skip brotli withheld on reference profile (#17563)');
}
--FILE--
<?php
$plain = 'hello brotli';
$c = brotli_compress($plain);
$u = brotli_uncompress($c);
echo is_string($c) ? '1' : '0';
echo "\n";
echo is_string($u) ? '1' : '0';
echo "\n";
echo $u === $plain ? '1' : '0';
echo "\n";
echo function_exists('brotli_compress') ? '1' : '0';
echo "\n";
echo extension_loaded('brotli') ? '1' : '0';
?>
--EXPECT--
1
1
1
1
1
