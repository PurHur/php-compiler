--TEST--
PHP_OUTPUT_HANDLER_PROCESSED Core constant on PROFILE≥8.4 (#28169)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';
if (!PHPCompiler\CompilerVersion::supportsPhpOutputHandlerProcessedConstant()) {
    die('skip requires PHP_COMPILER_PROFILE≥8.4 PHP_OUTPUT_HANDLER_PROCESSED');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'defined=', defined('PHP_OUTPUT_HANDLER_PROCESSED') ? '1' : '0', "\n";
echo 'val=', PHP_OUTPUT_HANDLER_PROCESSED, "\n";
--EXPECT--
defined=1
val=16384
