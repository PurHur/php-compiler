--TEST--
Language: #[\Deprecated] class const E_USER_DEPRECATED cites use-site line (#29381)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
    die('skip requires Deprecated runtime notices (PROFILE=8.4+)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    #[\Deprecated(message: 'use NEW', since: '8.4')]
    public const OLD = 1;
}

echo C::OLD, "\n";
--EXPECTF--
PHP Deprecated:  Constant C::OLD is deprecated since 8.4, use NEW in %s on line 7
1
