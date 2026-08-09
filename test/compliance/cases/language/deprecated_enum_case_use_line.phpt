--TEST--
Language: #[\Deprecated] enum case E_USER_DEPRECATED cites use-site line (#29381)
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
enum E {
    #[\Deprecated(message: 'old', since: '8.4')]
    case Old;
    case New;
}
echo E::Old->name, "\n";
--EXPECTF--
PHP Deprecated:  Enum case E::Old is deprecated since 8.4, old in %s on line 7
Old
