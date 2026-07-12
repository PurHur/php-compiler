--TEST--
Language: #[\AllowDynamicProperties] on enum accepted on reference profile (#17402, Zend 8.2)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::rejectsAllowDynamicPropertiesOnEnum()) {
    die('skip enum AllowDynamicProperties rejection enabled on PHP 8.5+ forward profile');
}
?>
--FILE--
<?php
#[\AllowDynamicProperties]
enum Bad: int {
    case X = 1;
}
echo "compiled\n";
--EXPECT--
compiled
