--TEST--
Language: final global typed constants rejected below PHP 8.4 target (#10324, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsFinalGlobalTypedConstants()) {
    die('skip final global typed constants enabled on PHP 8.4+ target');
}
?>
--FILE--
<?php
declare(strict_types=1);

final const string APP_NAME = 'alpha';

echo APP_NAME, "\n";
--EXPECT_EXIT--
255
