--TEST--
Language: final global typed constants rejected — Zend parse error (#10324, #15185, #16674, Zend/zend_compile.c)
--SKIPIF--
<?php
if (PHPCompiler\CompilerVersion::supportsFinalGlobalTypedConstants()) {
    die('skip reference profile gate only');
}
?>
--FILE--
<?php
declare(strict_types=1);

final const string APP_NAME = 'alpha';

echo APP_NAME, "\n";
--EXPECT_EXIT--
255
