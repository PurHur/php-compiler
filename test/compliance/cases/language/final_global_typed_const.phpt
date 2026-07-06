--TEST--
Language: file-scope final typed constants (PHP 8.4+, #15165, #16859, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsFinalGlobalTypedConstants()) {
    die('skip final global typed constants require PHP 8.4+ target');
}
?>
--FILE--
<?php
declare(strict_types=1);

final const string APP_NAME = 'alpha';

echo APP_NAME, "\n";
--EXPECT--
alpha
