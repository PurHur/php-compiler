--TEST--
Language: bare private(set)/protected(set) without read modifier — rejected on reference profile (#15446, Zend/zend_language_scanner.l)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsAsymmetricVisibility()) {
    die('skip bare set accepted on PHP 8.4.0+ target (#15694)');
}
?>
--FILE--
<?php
declare(strict_types=1);
class C {
    private(set) string $p = 'x';
}
echo "fail\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Syntax error, unexpected ')', expecting T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG in %s on line %d
