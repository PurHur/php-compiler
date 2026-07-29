--TEST--
Language: private(set) property rejected on reference profile (#15113, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsAsymmetricVisibility()) {
    die('skip asymmetric visibility enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php

declare(strict_types=1);

class C
{
    private(set) string $x = 'a';
}

$c = new C();
echo $c->x, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
Parse error: Syntax error, unexpected ')', expecting T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG in %s on line %d
