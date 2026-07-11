--TEST--
Language: asymmetric visibility public private(set) — reference profile gate (#17695, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsAsymmetricVisibility()) {
    die('skip asymmetric visibility enabled on PHP 8.4 forward profile');
}
?>
--FILE--
<?php
declare(strict_types=1);

class C
{
    public private(set) int $x = 1;
}
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Multiple access type modifiers are not allowed in %s on line %d
