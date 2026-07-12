--TEST--
Language: #[\AllowDynamicProperties] on enum compile-time fatal on PHP 8.5+ (#9734, php-src GH-15731)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::rejectsAllowDynamicPropertiesOnEnum()) {
    die('skip enum AllowDynamicProperties rejection disabled on reference profile');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
#[\AllowDynamicProperties]
enum Bad: int {
    case X = 1;
}
echo "compiled\n";
--EXPECT_EXIT--
255
