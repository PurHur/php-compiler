--TEST--
ReflectionConstant::inNamespace on PROFILE=8.6 (#22662, php/php-src#20902)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.6');
if (!PHPCompiler\CompilerVersion::advertisesReflectionConstantInNamespace()) {
    die('skip ReflectionConstant::inNamespace requires PHP_COMPILER_PROFILE=8.6');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.6
--FILE--
<?php
define('Foo\\BAR', 1);
define('GLOBAL_C', 2);
$r = new ReflectionConstant('Foo\\BAR');
echo method_exists($r, 'inNamespace') ? "inNs-yes\n" : "inNs-no\n";
echo method_exists($r, 'getFileName') ? "file-yes\n" : "file-no\n";
echo var_export($r->inNamespace(), true), "\n";
$g = new ReflectionConstant('GLOBAL_C');
echo var_export($g->inNamespace(), true), "\n";
$pi = new ReflectionConstant('PHP_VERSION');
echo var_export($pi->inNamespace(), true), "\n";
--EXPECT--
inNs-yes
file-yes
true
false
false
