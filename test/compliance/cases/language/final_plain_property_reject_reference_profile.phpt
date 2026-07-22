--TEST--
Language: final plain property rejected on 8.2 reference profile (#22308, re-#22241, Zend/zend_compile.c)
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
class C {
    public final string $x = 'a';
}
echo "parsed_ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot declare property C::$x final, the final modifier is allowed only for methods, classes, and class constants
