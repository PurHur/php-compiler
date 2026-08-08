--TEST--
Language: PHP 8.4 hexadecimal floating-point literals (#7041, #29061)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsHexFloatLiterals()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4 hex-float gate (#29061)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 0x1.8p+1, "\n";
echo 0xA.Fp-2, "\n";
echo 0x1p+1, "\n";
var_dump(0x1.8p+1 === 3.0);
--EXPECT--
3
2.734375
2
bool(true)
