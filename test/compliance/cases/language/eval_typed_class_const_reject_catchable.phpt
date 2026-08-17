--TEST--
Language: eval(typed class const) catchable ParseError under PROFILE=8.2 (#31860, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.2');
if (PHPCompiler\CompilerVersion::supportsTypedClassConstants()) {
    die('skip PROFILE=8.2 unexpectedly enables typed class constants (#31860)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
try {
    eval('class C { public const int X = 1; }');
    echo "accepted\n";
} catch (ParseError $e) {
    echo 'ParseError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "after\n";
--EXPECT--
ParseError:syntax error, unexpected identifier "X", expecting "="
after
