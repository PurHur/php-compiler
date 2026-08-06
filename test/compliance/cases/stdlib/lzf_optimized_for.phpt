--TEST--
stdlib lzf_optimized_for() returns PHP_LZF_ULTRA_FAST (1) when lzf advertised (#28063, pecl-file_formats-lzf)
--ENV--
PHP_COMPILER_ENABLE_LZF=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\lzf\LzfExtensionPolicy::advertisesExtension()) {
    die('skip lzf withheld (#25287)');
}
?>
--FILE--
<?php
echo function_exists('lzf_optimized_for') ? '1' : '0';
echo (1 === lzf_optimized_for()) ? '1' : '0';
$r = new ReflectionFunction('lzf_optimized_for');
echo $r->hasReturnType() && (string) $r->getReturnType() === 'int|false' ? '1' : '0';
echo 0 === $r->getNumberOfParameters() ? '1' : '0';
echo "\n";
?>
--EXPECT--
1111
