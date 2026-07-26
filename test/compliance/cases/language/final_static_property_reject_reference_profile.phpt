--TEST--
Language: final static property rejected on 8.2 reference profile (#23403, re-#22308, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsFinalProperties()) {
    die('skip final properties enabled on PHP 8.4+ forward profile');
}
?>
--FILE--
<?php
class A {
    public final static $x = 1;
}
echo "parsed_ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot declare property A::$x final, the final modifier is allowed only for methods, classes, and class constants
